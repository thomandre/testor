<?php

declare(strict_types=1);

namespace Testor\Analysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Static analysis of source files (no code is loaded): per class and method, the side
 * effects it may have and the inferred domain of each parameter.
 *
 * Result: [FQCN => [
 *     'ctorProperties' => [constructor param => property it is stored in],
 *     'methods' => [lowercase method => [
 *         'sideEffects' => list<string>,
 *         'domains' => [param => domain|null],
 *         'paramNames' => list<string>,
 *         'calls' => ['property' => [property => list<method>], 'variable' => [variable => list<method>]],
 *         'flows' => where dependency return values go (see DomainExtractor::dependencyFlows()),
 *     ]],
 *     'readProperties' => names of properties read on other objects,
 * ]]
 * Calls are the methods invoked on dependencies ($this->prop->m(), $param->m()), lowercase;
 * like side effects, calls through the class's own methods are followed.
 */
final class SourceAnalyzer
{
    private const SIDE_EFFECT_FUNCTIONS = [
        'file_put_contents', 'fopen', 'fwrite', 'fputs', 'unlink', 'mkdir', 'rmdir', 'rename', 'copy',
        'touch', 'chmod', 'chown', 'tempnam', 'tmpfile', 'move_uploaded_file', 'fsockopen', 'pfsockopen',
        'mail', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'header', 'setcookie',
        'setrawcookie', 'sleep', 'usleep', 'set_time_limit', 'ini_set', 'putenv', 'error_log',
        'gethostbyname', 'dns_get_record', 'get_headers',
    ];
    private const SIDE_EFFECT_PREFIXES = ['curl_', 'socket_', 'stream_socket_', 'ftp_', 'mysqli_', 'session_', 'pcntl_', 'posix_'];
    private const SIDE_EFFECT_CLASSES = ['pdo', 'mysqli', 'soapclient', 'splfileobject'];

    /** Per class-like: parent, traits, and per method its direct effects, internal calls, domains. */
    private array $raw = [];
    /** @var array<string, array<string, string>> FQCN => [lowercase method => "Declaring::method"] */
    private array $tables = [];

    /**
     * @param list<string> $files files of the classes to describe, plus the files of their
     *                            ancestors and traits (so inherited calls can be followed)
     */
    public function analyse(array $files): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $this->raw = [];
        $this->tables = [];

        foreach (array_unique($files) as $file) {
            try {
                $ast = $parser->parse((string) file_get_contents($file)) ?? [];
            } catch (Error) {
                continue; // the reflection worker will report what it can
            }
            $resolver = new NameResolver();
            $collector = new class ($resolver, $this) extends NodeVisitorAbstract {
                public function __construct(private NameResolver $resolver, private SourceAnalyzer $analyzer)
                {
                }

                public function leaveNode(Node $node): null
                {
                    // On leave, NameResolver has resolved every name inside the class body,
                    // and the name context (namespace, imports) is still the class's one.
                    if ($node instanceof Stmt\ClassLike && $node->namespacedName !== null) {
                        $this->analyzer->collect($node, clone $this->resolver->getNameContext());
                    }
                    return null;
                }
            };
            (new NodeTraverser($resolver, $collector))->traverse($ast);
        }

        $result = [];
        foreach (array_keys($this->raw) as $class) {
            $result[$class] = $this->describe($class);
        }
        return $result;
    }

    /** @internal called by the traversal visitor */
    public function collect(Stmt\ClassLike $class, \PhpParser\NameContext $names): void
    {
        $name = $class->namespacedName->toString();
        try {
            $parent = $class instanceof Stmt\Class_ ? $class->extends?->toString() : null;
            $domains = new DomainExtractor($name, $parent, $names);
            $traits = [];
            foreach ($class->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }
            $methods = [];
            foreach ($class->getMethods() as $method) {
                [$effects, $calls] = $this->scanBody($method->stmts ?? []);
                $paramDomains = [];
                foreach ($method->params as $param) {
                    if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                        $paramDomains[$param->var->name] = $domains->domain($method, $param->var->name);
                    }
                }
                // Flows of dependency return values propagate to callers like effects do.
                foreach ($domains->dependencyFlows($method) as $flow) {
                    $effects[] = 'flow:' . json_encode($flow, JSON_THROW_ON_ERROR);
                }
                $methods[$method->name->toLowerString()] = ['effects' => $effects, 'calls' => $calls,
                    'domains' => $paramDomains, 'paramNames' => array_keys($paramDomains)];
            }
            $this->raw[$name] = ['parent' => $parent, 'traits' => $traits, 'isTrait' => $class instanceof Stmt\Trait_,
                'methods' => $methods, 'ctorProperties' => $this->ctorProperties($class),
                'readProperties' => $this->readProperties($class)];
        } catch (\Throwable $e) {
            // Degrade gracefully: no domains/side effects for this class.
            fwrite(STDERR, "warning: static analysis of $name failed: {$e->getMessage()}\n");
        }
    }

    /** Side effects (following inherited and trait methods) and domains of a class's methods. */
    private function describe(string $class): array
    {
        $table = $this->table($class);
        $effects = [];
        $pending = array_values(array_unique($table));
        foreach ($pending as $origin) {
            $effects[$origin] = $this->rawMethod($origin)['effects'] ?? [];
        }

        // Fixpoint: a method has the effects of every method it may call.
        do {
            $changed = false;
            foreach (array_keys($effects) as $origin) {
                foreach ($this->rawMethod($origin)['calls'] ?? [] as [$kind, $method]) {
                    $target = $this->resolveCall($class, $origin, $kind, $method);
                    if ($target === null) {
                        continue;
                    }
                    if (!isset($effects[$target])) {
                        $effects[$target] = $this->rawMethod($target)['effects'] ?? [];
                        $changed = true;
                    }
                    $new = array_diff($effects[$target], $effects[$origin]);
                    if ($new !== []) {
                        $effects[$origin] = [...$effects[$origin], ...$new];
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        $methods = [];
        foreach ($table as $name => $origin) {
            $sideEffects = [];
            $calls = ['property' => [], 'variable' => []];
            $flows = [];
            foreach (array_unique($effects[$origin]) as $effect) {
                if (preg_match('/^call:(property|variable):(\w+)->(\w+)$/', $effect, $m)) {
                    $calls[$m[1]][$m[2]][] = $m[3];
                } elseif (str_starts_with($effect, 'flow:')) {
                    $flows[] = json_decode(substr($effect, 5), true);
                } else {
                    $sideEffects[] = $effect;
                }
            }
            $methods[$name] = [
                'sideEffects' => $sideEffects,
                'domains' => $this->rawMethod($origin)['domains'] ?? [],
                'paramNames' => $this->rawMethod($origin)['paramNames'] ?? [],
                'calls' => $calls,
                'flows' => $flows,
            ];
        }

        $ctorOrigin = $table['__construct'] ?? null;
        $ctorClass = $ctorOrigin === null ? $class : substr($ctorOrigin, 0, strrpos($ctorOrigin, '::'));
        return [
            'ctorProperties' => $this->raw[$ctorClass]['ctorProperties'] ?? [],
            'readProperties' => $this->raw[$class]['readProperties'] ?? [],
            'methods' => $methods,
        ];
    }

    /** Names of the properties the class reads on other objects ($order->status, $x?->feature). */
    private function readProperties(Stmt\ClassLike $class): array
    {
        $names = [];
        $fetches = (new NodeFinder())->find($class->stmts, fn (Node $n) => $n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch);
        foreach ($fetches as $fetch) {
            $onThis = $fetch->var instanceof Expr\Variable && $fetch->var->name === 'this';
            if (!$onThis && $fetch->name instanceof Node\Identifier) {
                $names[$fetch->name->toString()] = true;
            }
        }
        return array_keys($names);
    }

    /** Constructor parameter => property: promoted parameters and `$this->prop = $param;` assignments. */
    private function ctorProperties(Stmt\ClassLike $class): array
    {
        $ctor = $class->getMethod('__construct');
        if ($ctor === null) {
            return [];
        }
        $map = [];
        foreach ($ctor->params as $param) {
            if ($param->flags !== 0 && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $map[$param->var->name] = $param->var->name;
            }
        }
        foreach ((new NodeFinder())->findInstanceOf($ctor->stmts ?? [], Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Expr\PropertyFetch && $assign->var->var instanceof Expr\Variable
                && $assign->var->var->name === 'this' && $assign->var->name instanceof Node\Identifier
                && $assign->expr instanceof Expr\Variable && is_string($assign->expr->name)) {
                $map[$assign->expr->name] = $assign->var->name->toString();
            }
        }
        return $map;
    }

    /**
     * Method resolution like PHP's: own methods override trait methods, which override inherited ones.
     *
     * @return array<string, string> lowercase method => "DeclaringClass::method"
     */
    private function table(string $class): array
    {
        if (isset($this->tables[$class])) {
            return $this->tables[$class];
        }
        $this->tables[$class] = []; // guards against inheritance cycles
        $raw = $this->raw[$class] ?? null;
        if ($raw === null) {
            return [];
        }
        $table = $raw['parent'] !== null ? $this->table($raw['parent']) : [];
        foreach ($raw['traits'] as $trait) {
            $table = [...$table, ...$this->table($trait)];
        }
        foreach (array_keys($raw['methods']) as $method) {
            $table[$method] = "$class::$method";
        }
        return $this->tables[$class] = $table;
    }

    private function resolveCall(string $class, string $origin, string $kind, string $method): ?string
    {
        $declaring = substr($origin, 0, strrpos($origin, '::'));
        $isTrait = $this->raw[$declaring]['isTrait'] ?? false;
        return match ($kind) {
            // $this->x() / static::x(): late binding on the class under test.
            'this' => $this->table($class)[$method] ?? null,
            // self::x() in a trait means the using class.
            'self' => ($isTrait ? $this->table($class) : $this->table($declaring))[$method] ?? null,
            'parent' => ($p = $this->raw[$declaring]['parent'] ?? null) !== null ? $this->table($p)[$method] ?? null : null,
            default => null,
        };
    }

    private function rawMethod(string $origin): ?array
    {
        [$class, $method] = explode('::', $origin, 2);
        return $this->raw[$class]['methods'][$method] ?? null;
    }

    /** @return array{list<string>, list<array{string, string}>} [side effects, internal calls [kind, method]] */
    private function scanBody(array $stmts): array
    {
        $effects = [];
        $internal = [];
        foreach ((new NodeFinder())->find($stmts, fn () => true) as $node) {
            if ($node instanceof Expr\Exit_) {
                $effects[] = 'exit';
            } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
                $fn = $node->name->toLowerString();
                if ($this->isSideEffectFunction($fn)) {
                    $effects[] = $fn;
                }
            } elseif ($node instanceof Expr\New_ && $node->class instanceof Name) {
                $short = strtolower($node->class->getLast());
                if (in_array($short, self::SIDE_EFFECT_CLASSES, true)) {
                    $effects[] = "new $short";
                }
            } elseif (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
                && $node->var instanceof Expr\Variable && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier) {
                $internal[] = ['this', $node->name->toLowerString()];
            } elseif (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier) {
                // Calls on dependencies, $this->prop->m() and $variable->m(): recorded like
                // effects (prefixed) so they propagate through the class's own methods too.
                $target = $node->var;
                if (($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch)
                    && $target->var instanceof Expr\Variable && $target->var->name === 'this'
                    && $target->name instanceof Node\Identifier) {
                    $effects[] = "call:property:{$target->name->toString()}->{$node->name->toLowerString()}";
                } elseif ($target instanceof Expr\Variable && is_string($target->name)) {
                    $effects[] = "call:variable:{$target->name}->{$node->name->toLowerString()}";
                }
            } elseif ($node instanceof Expr\StaticCall && $node->class instanceof Name
                && in_array($node->class->toLowerString(), ['self', 'static', 'parent'], true)
                && $node->name instanceof Node\Identifier) {
                $kind = $node->class->toLowerString();
                $internal[] = [$kind === 'static' ? 'this' : $kind, $node->name->toLowerString()];
            }
        }
        return [$effects, array_values(array_unique($internal, SORT_REGULAR))];
    }

    private function isSideEffectFunction(string $name): bool
    {
        if (in_array($name, self::SIDE_EFFECT_FUNCTIONS, true)) {
            return true;
        }
        foreach (self::SIDE_EFFECT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}

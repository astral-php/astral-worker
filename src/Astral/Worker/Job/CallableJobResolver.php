<?php

declare(strict_types=1);

namespace Astral\Worker\Job;

use Astral\Worker\Exception\JobNotFoundException;
use Closure;

/** Résout un job via une fabrique (ex. : fn(string $c) => $container->make($c)). */
final class CallableJobResolver implements JobResolverInterface
{
    private Closure $factory;

    public function __construct(callable $factory)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    public function resolve(string $class): JobInterface
    {
        if (!class_exists($class)) {
            throw new JobNotFoundException("Classe de job introuvable : {$class}");
        }

        $job = ($this->factory)($class);

        if (!$job instanceof JobInterface) {
            throw new JobNotFoundException("{$class} doit implémenter " . JobInterface::class);
        }

        return $job;
    }
}

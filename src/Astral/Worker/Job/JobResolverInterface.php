<?php

declare(strict_types=1);

namespace Astral\Worker\Job;

interface JobResolverInterface
{
    /** @throws \Astral\Worker\Exception\JobNotFoundException */
    public function resolve(string $class): JobInterface;
}

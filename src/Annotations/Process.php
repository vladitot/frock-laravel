<?php

namespace FrockDev\ToolsForLaravel\Annotations;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Process
{
    public function __construct(
        public string $name
    ){}
}

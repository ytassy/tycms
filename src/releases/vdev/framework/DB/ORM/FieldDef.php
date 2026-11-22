<?php

namespace YTassy\Tycms\DB\ORM;


class FieldDef 
{
    public function __construct(
        public readonly string $name,
        public readonly bool $hidden,
        public readonly array $attributes,
    ) {
    }

}

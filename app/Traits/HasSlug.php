<?php

namespace App\Traits;

trait HasSlug
{
    public function generateSlug(string $name, ?int $excludeId = null): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9가-힣\s-]/', '', $name));
        $slug = str_replace(' ', '-', trim($base));

        $original = $slug;
        $i = 1;
        while (true) {
            $q = $this->where('slug', $slug);
            if ($excludeId) $q->where('id !=', $excludeId);
            if (! $q->countAllResults()) break;
            $slug = $original . '-' . $i++;
        }
        return $slug;
    }
}

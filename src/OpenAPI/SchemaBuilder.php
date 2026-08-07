<?php // src/OpenAPI/SchemaBuilder.php
namespace Falco\OpenAPI;

use Falco\Model;

final class SchemaBuilder
{
    public function fromType(?\ReflectionType $type): array
    {
        if ($type === null) return [];
        if ($type instanceof \ReflectionUnionType) {
            $schemas = array_map(fn($t) => $this->fromNamed($t), $type->getTypes());
            return ['anyOf' => array_values(array_filter($schemas, fn($s) => $s !== []))];
        }
        return $this->fromNamed($type);
    }

    public function fromNamed(\ReflectionNamedType $type): array
    {
        $name = $type->getName();
        $schema = match ($name) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            default => $this->named($name),
        };
        if ($type->allowsNull() && $schema !== []) {
            $schema['nullable'] = true;
        }
        return $schema;
    }

    private function named(string $name): array
    {
        if (is_subclass_of($name, Model::class)) {
            return ['$ref' => '#/components/schemas/' . (new \ReflectionClass($name))->getShortName()];
        }
        if (is_subclass_of($name, \BackedEnum::class)) {
            return ['type' => 'string', 'enum' => array_map(fn($c) => $c->value, $name::cases())];
        }
        return [];
    }

    public function fromModel(string $class): array
    {
        $properties = [];
        $required = [];
        $ref = new \ReflectionClass($class);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $properties[$prop->getName()] = $this->fromType($prop->getType());
            if (!$prop->hasDefaultValue()) {
                $required[] = $prop->getName();
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required) $schema['required'] = $required;
        return $schema;
    }
}
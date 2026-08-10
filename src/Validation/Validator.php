<?php // src/Validation/Validator.php
namespace Falco\Validation;

use Falco\Model;

/**
 * Coerces a raw value to a reflected type (int/float/string/bool/array/null,
 * BackedEnum, Model subclass, and union types). Type mismatches throw
 * {@see ValidationException} with a Falco `loc`/`msg`/`type` payload.
 */
final class Validator
{
    public function coerce(mixed $value, ?\ReflectionType $type, array $loc): mixed
    {
        if ($type === null) {
            return $value;
        }
        if ($type instanceof \ReflectionUnionType) {
            $types = $type->getTypes();
            $nullable = false;
            foreach ($types as $t) {
                if ($t->getName() === 'null') { $nullable = true; break; }
            }
            if ($value === null) {
                if ($nullable) return null;
                throw new ValidationException([$this->err($loc, 'Input should not be null', 'nullable_type')]);
            }
            $last = null;
            foreach ($types as $t) {
                if ($t->getName() === 'null') continue;
                try {
                    return $this->coerceNamed($value, $t, $loc);
                } catch (ValidationException $e) {
                    $last = $e;
                }
            }
            throw $last ?? new ValidationException([$this->err($loc, 'Input does not match any type', 'union_type')]);
        }
        if ($type instanceof \ReflectionNamedType) {
            return $this->coerceNamed($value, $type, $loc);
        }
        return $value;
    }

    private function coerceNamed(mixed $value, \ReflectionNamedType $type, array $loc): mixed
    {
        if ($type->allowsNull() && $value === null) {
            return null;
        }
        return match ($type->getName()) {
            'int' => $this->int($value, $loc),
            'float' => $this->float($value, $loc),
            'string' => $this->string($value, $loc),
            'bool' => $this->bool($value, $loc),
            'array' => is_array($value) ? $value : throw $this->err($loc, 'Input should be an array', 'array_type'),
            'null' => null,
            default => $this->modelOrEnum($value, $type->getName(), $loc),
        };
    }

    private function int(mixed $v, array $loc): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int) $v;
        if (is_float($v) && floor($v) === $v) return (int) $v;
        throw $this->err($loc, 'Input should be a valid integer', 'int_parsing');
    }

    private function float(mixed $v, array $loc): float
    {
        if (is_int($v)) return (float) $v;
        if (is_float($v)) return $v;
        if (is_string($v) && is_numeric($v)) return (float) $v;
        throw $this->err($loc, 'Input should be a valid number', 'float_parsing');
    }

    private function string(mixed $v, array $loc): string
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string) $v;
        throw $this->err($loc, 'Input should be a valid string', 'string_type');
    }

    private function bool(mixed $v, array $loc): bool
    {
        if (is_bool($v)) return $v;
        if (in_array($v, [1, '1', 'true', 'True', 'TRUE'], true)) return true;
        if (in_array($v, [0, '0', 'false', 'False', 'FALSE'], true)) return false;
        throw $this->err($loc, 'Input should be a valid boolean', 'bool_parsing');
    }

    private function modelOrEnum(mixed $v, string $typeName, array $loc): mixed
    {
        if (is_subclass_of($typeName, Model::class)) {
            if (!is_array($v)) throw $this->err($loc, 'Input should be an object', 'model_type');
            return $typeName::fromArray($v);
        }
        if (is_subclass_of($typeName, \BackedEnum::class)) {
            foreach ($typeName::cases() as $case) {
                if ($case->value === $v) return $case;
            }
            throw $this->err($loc, 'Input should be a valid enum value', 'enum_parsing');
        }
        throw $this->err($loc, 'Unsupported parameter type', 'unsupported_type');
    }

    private function err(array $loc, string $msg, string $type): ValidationException
    {
        return new ValidationException([['loc' => $loc, 'msg' => $msg, 'type' => $type]]);
    }
}

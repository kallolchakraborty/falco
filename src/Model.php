<?php // src/Model.php
namespace Falco;

use Falco\Validation\Validator;
use Falco\Validation\ValidationException;

/**
 * Base class for request-body models. Subclass it and declare public typed
 * properties; {@see Validator} coerces incoming data and {@see fromArray()}
 * throws a FastAPI-shaped `ValidationException` (422) for missing/invalid fields.
 */
abstract class Model
{
    public static function fromArray(array $data): static
    {
        $validator = new Validator();
        $instance = new static();
        $ref = new \ReflectionClass(static::class);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $name = $prop->getName();
            if (!array_key_exists($name, $data)) {
                if ($prop->hasDefaultValue()) continue;
                if ($prop->getType()?->allowsNull()) continue;
                throw new ValidationException([[
                    'loc' => ['body', $name],
                    'msg' => 'Field required',
                    'type' => 'missing',
                ]]);
            }
            $instance->$name = $validator->coerce($data[$name], $prop->getType(), ['body', $name]);
        }
        return $instance;
    }

    public function toArray(): array
    {
        $result = [];
        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $value = $this->{$prop->getName()};
            $result[$prop->getName()] = $value instanceof self ? $value->toArray() : $value;
        }
        return $result;
    }
}

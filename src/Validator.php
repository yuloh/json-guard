<?php

namespace Machete\Validation;

class Validator
{
    /**
     * @var array
     */
    private $errors = [];

    /**
     * @var mixed
     */
    private $data;

    /**
     * @var object
     */
    private $schema;

    /**
     * @var string
     */
    private $pointer = '';

    /**
     * Set the maximum depth the validator should recurse into $data
     * before throwing an exception.  You should only need to modify
     * this if you are using circular references in your schema.
     *
     * @var int
     */
    private $maxDepth = 10;

    /**
     * The depth the current validator has reached in the data.
     *
     * @var int
     */
    private $depth = 0;

    /**
     * @param mixed  $data
     * @param object $schema
     */
    public function __construct($data, $schema)
    {
        if (!is_object($schema)) {
            throw new \InvalidArgumentException(
                sprintf('The schema should be an object from a json_decode call, got "%s"', gettype($schema))
            );
        }

        if ($schema instanceof Reference) {
            $schema = $schema->resolve();
        }

        $this->data   = $data;
        $this->schema = $schema;
    }

    protected function validate()
    {
        $this->errors = [];

        $this->checkDepth();

        foreach ($this->schema as $rule => $parameter) {
            $method = sprintf('validate%s', ucfirst($rule));

            if (method_exists($this, $method)) {
                try {
                    $this->$method($parameter);
                } catch (AssertionFailedException $e) {
                    $this->errors[] = exceptionToError($e);
                }
            }
        }
    }

    /**
     * @return boolean
     */
    public function fails()
    {
        return !$this->passes();
    }

    /**
     * @return boolean
     */
    public function passes()
    {
        return empty($this->errors());
    }

    /**
     * Get a collection of errors.
     */
    public function errors()
    {
        $this->validate();

        return $this->errors;
    }

    /**
     * Set the maximum allowed depth data will be validated until.
     * If the data exceeds the stack depth an exception is thrown.
     *
     * @param int $maxDepth
     * @return $this
     */
    public function setMaxDepth($maxDepth)
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    /**
     * @internal
     * @param $depth
     * @return $this
     */
    public function setDepth($depth)
    {
        $this->depth = $depth;

        return $this;
    }

    /**
     * @internal
     * @return string
     */
    public function getPointer()
    {
        return $this->pointer;
    }

    /**
     * @internal
     * @param string $pointer
     * @return $this
     */
    public function setPointer($pointer)
    {
        $this->pointer = $pointer;

        return $this;
    }

    protected function validateItems($parameter)
    {
        if (!is_array($this->data)) {
            return;
        }

        foreach ($this->data as $k => $v) {
            if (is_object($parameter)) {
                // list validation
                $schema = $parameter;
            } elseif (is_array($parameter)) {
                // tuple validation
                if (array_key_exists($k, $parameter)) {
                    $schema = $parameter[$k];
                }
            }

            // Additional items are allowed by default,
            // so there might not be a schema for this.

            if (isset($schema)) {
                $validator = $this->create($v, $schema, $this->pointer . '/' . $k);
                if ($validator->fails()) {
                    $this->errors = array_merge($this->errors, $validator->errors());
                }
            }

            unset($schema);
        }
    }

    /**
     * @param object|bool $parameter
     */
    protected function validateAdditionalItems($parameter)
    {
        if (!is_array($this->data)) {
            return;
        }

        $hasItemsSchema = property_exists($this->schema, 'items');

        $itemSchema = $hasItemsSchema ? $this->schema->items : [];

        // When items is a single schema, the additionalItems keyword is meaningless, and it should not be used.
        if (!is_array($itemSchema)) {
            return;
        }

        // If items is not defined, items defaults to an empty schema so everything is valid.
        // If the item schema exists and additionalItems is false, make sure there aren't any additional items.
        if ($parameter === false && $hasItemsSchema) {
            Assert::max(count($this->data), count($itemSchema), $this->getPointer());
        } elseif (is_object($parameter)) {
            // its a schema, so validate every additional item matches.
            $additional = array_slice($this->data, count($itemSchema));
            foreach ($additional as $key => $item) {
                $validator = $this->create($item, $parameter, $this->pointer . '/' . $key);
                if ($validator->fails()) {
                    $this->errors = array_merge($this->errors, $validator->errors());
                }
            }
        }
    }

    /**
     * @param array|object $parameter
     */
    protected function validateAllOf($parameter)
    {
        if (!is_array($parameter)) {
            return;
        }

        foreach ($parameter as $schema) {
            $validator = $this->create($this->data, $schema, $this->pointer);
            if ($validator->fails()) {
                $this->errors = array_merge($this->errors, $validator->errors());
            }
        }
    }

    /**
     * @param array|object $parameter
     */
    protected function validateAnyOf($parameter)
    {
        if (!is_array($parameter)) {
            return;
        }

        foreach ($parameter as $schema) {
            $validator = $this->create($this->data, $schema, $this->pointer);
            if ($validator->passes()) {
                return;
            }
        }
        throw new AssertionFailedException(
            'Failed matching any of the provided schemas.',
            ANY_OF_SCHEMA,
            $this->data,
            $this->pointer,
            ['any_of' => $parameter]
        );
    }

    /**
     * @param array|object $parameter
     */
    protected function validateOneOf($parameter)
    {
        if (!is_array($parameter)) {
            return;
        }

        $passed = 0;
        foreach ($parameter as $schema) {
            $validator = $this->create($this->data, $schema, $this->pointer);
            if ($validator->passes()) {
                $passed++;
            }
        }
        if ($passed !== 1) {
            throw new AssertionFailedException(
                'Failed matching exactly one of the provided schemas.',
                ONE_OF_SCHEMA,
                $this->data,
                $this->pointer,
                ['one_of' => $parameter]
            );
        }
    }

    /**
     * @param array|object $parameter
     */
    protected function validateDependencies($parameter)
    {
        foreach ($parameter as $property => $dependencies) {
            if (!is_object($this->data) || !property_exists($this->data, $property)) {
                continue;
            }
            if (is_array($dependencies)) {
                Assert::allInArray($dependencies, array_keys(get_object_vars($this->data)), $this->getPointer());
            } elseif (is_object($dependencies)) {
                // if its an object it is a schema that all dependencies
                // must validate against.
                $validator = $this->create(
                    $this->data,
                    $dependencies,
                    $this->pointer
                );
                if ($validator->fails()) {
                    $this->errors = array_merge($this->errors, $validator->errors());
                }
            }
        }
    }

    /**
     * @param array $parameter
     */
    protected function validateEnum($parameter)
    {
        Assert::inArray($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param object $parameter
     */
    protected function validateNot($parameter)
    {
        $validator = $this->create($this->data, $parameter, $this->pointer);
        if ($validator->passes()) {
            throw new AssertionFailedException(
                'Data should not match the schema.',
                NOT_SCHEMA,
                $this->data,
                $this->pointer,
                ['not_schema' => $parameter]
            );
        }
    }

    /**
     * @param int $parameter
     */
    protected function validateMaxLength($parameter)
    {
        if (!is_string($this->data)) {
            return;
        }
        Assert::maxLength($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMinLength($parameter)
    {
        if (!is_string($this->data)) {
            return;
        }
        Assert::minLength($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMinimum($parameter)
    {
        if (!is_numeric($this->data)) {
            return;
        }

        if (array_key_exists('exclusiveMinimum', get_object_vars($this->schema))) {
            Assert::exclusiveMin($this->data, $parameter, $this->getPointer());

            return;
        }

        Assert::min($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMaximum($parameter)
    {
        if (!is_numeric($this->data)) {
            return;
        }

        if (array_key_exists('exclusiveMaximum', get_object_vars($this->schema))) {
            Assert::exclusiveMax($this->data, $parameter, $this->getPointer());

            return;
        }

        Assert::max($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMinItems($parameter)
    {
        if (!is_array($this->data)) {
            return;
        }
        Assert::minItems($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMaxItems($parameter)
    {
        if (!is_array($this->data)) {
            return;
        }
        Assert::maxItems($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMaxProperties($parameter)
    {
        if (!is_object($this->data)) {
            return;
        }
        Assert::maxProperties($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int $parameter
     */
    protected function validateMinProperties($parameter)
    {
        if (!is_object($this->data)) {
            return;
        }
        Assert::minProperties($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param int|float $parameter
     */
    protected function validateMultipleOf($parameter)
    {
        if (!is_numeric($this->data)) {
            return;
        }
        Assert::multipleOf($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param string $parameter
     */
    protected function validatePattern($parameter)
    {
        if (!is_string($this->data)) {
            return;
        }
        Assert::regex($this->data, '/' . str_replace('/', '\\/', $parameter) . '/', $this->getPointer());
    }

    /**
     * @param object $parameter
     */
    protected function validatePatternProperties($parameter)
    {
        if (!is_object($this->data)) {
            return;
        }

        foreach ($parameter as $property => $schema) {
            $matches       = $this->propertiesMatchingPattern($property, $this->data);
            $matchedSchema = array_fill_keys($matches, $schema);
            $this->assertPropertiesAreValid($matchedSchema);
        }
    }

    /**
     * @param array $parameter
     */
    protected function validateRequired($parameter)
    {
        $actualProperties = array_keys(get_object_vars($this->data));
        $missing          = array_diff($parameter, $actualProperties);
        if (count($missing)) {
            throw new AssertionFailedException(
                'Required properties missing.',
                MISSING_REQUIRED,
                $this->data,
                $this->pointer,
                ['required' => $parameter]
            );
        }
    }

    /**
     * @param object $parameter
     */
    protected function validateProperties($parameter)
    {
        if (!is_object($this->data)) {
            return;
        }

        $this->assertPropertiesAreValid($parameter);
    }

    /**
     * @param object $parameter
     */
    protected function validateAdditionalProperties($parameter)
    {
        if (!is_object($this->data)) {
            return;
        }

        if (property_exists($this->schema, 'properties')) {
            $definedProperties = array_keys(get_object_vars($this->schema->properties));
        } else {
            $definedProperties = [];
        }
        $actualProperties = array_keys(get_object_vars($this->data));
        $diff             = array_diff($actualProperties, $definedProperties);

        // The diff doesn't account for patternProperties, so lets filter those out too.
        if (property_exists($this->schema, 'patternProperties')) {
            foreach ($this->schema->patternProperties as $property => $schema) {
                $matches = $this->propertiesMatchingPattern($property, $diff);
                $diff    = array_diff($diff, $matches);
            }
        }

        if (count($diff)) {
            if ($parameter === false) {
                throw new AssertionFailedException(
                    'Additional properties are not allowed.',
                    NOT_ALLOWED_PROPERTY,
                    $this->data,
                    $this->pointer
                );
            } elseif (is_object($parameter)) {
                // If additionalProperties is an object it's a schema,
                // so validate all additional properties against it.
                $additionalSchema = array_fill_keys($diff, $parameter);
                $this->assertPropertiesAreValid($additionalSchema);
            }
        }
    }

    /**
     * @param array|string $parameter
     */
    protected function validateType($parameter)
    {
        if (is_array($parameter)) {
            Assert::anyType($this->data, $parameter, $this->getPointer());

            return;
        }

        Assert::type($this->data, $parameter, $this->getPointer());
    }

    protected function validateUniqueItems()
    {
        if (!is_array($this->data)) {
            return;
        }

        Assert::unique($this->data, $this->getPointer());
    }

    /**
     * @param string $parameter
     */
    protected function validateFormat($parameter)
    {
        Assert::format($this->data, $parameter, $this->getPointer());
    }

    /**
     * @param array|object $parameter
     */
    protected function assertPropertiesAreValid($parameter)
    {
        // Iterate through the properties and create a new
        // validator for that property's schema and data.
        // Merge the errors with our own validator.
        foreach ($parameter as $property => $schema) {
            if (is_object($this->data) && property_exists($this->data, $property)) {
                $data      = $this->data->$property;
                $validator = $this->create($data, $schema, $this->pointer . '/' . $property);
                if ($validator->fails()) {
                    $this->errors = array_merge($this->errors, $validator->errors());
                }
            }
        }
    }

    /**
     * Get the properties matching a given pattern from the $data.
     *
     * @param string       $pattern
     * @param array|object $data
     * @return array
     */
    protected function propertiesMatchingPattern($pattern, $data)
    {
        // If an object is supplied, extract an array of the property names.
        if (is_object($data)) {
            $data = array_keys(get_object_vars($this->data));
        }

        $pattern = '/' . str_replace('/', '\\/', $pattern) . '/';

        return preg_grep($pattern, $data);
    }

    /**
     * Create a new sub-validator.
     *
     * @param mixed  $data
     * @param object $schema
     * @param string $pointer
     * @return Validator
     */
    protected function create($data, $schema, $pointer)
    {
        $v = new Validator($data, $schema);
        $v->setPointer($pointer);
        $v->setMaxDepth($this->maxDepth);
        $v->setDepth($this->depth + 1);

        return $v;
    }

    /**
     * Keep track of how many levels deep we have validated.
     * This is to prevent a really deeply nested JSON
     * structure from causing the validator to continue
     * validating for an incredibly long time.
     */
    protected function checkDepth()
    {
        if ($this->depth > $this->maxDepth) {
            throw new MaximumDepthExceededException();
        }
    }
}
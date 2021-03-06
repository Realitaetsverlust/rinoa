<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Checker\Statements\Expression\AssertionFinder;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Issue\InvalidReturnType;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Reconciler;
use Psalm\Type\TypeCombination;

class FunctionChecker extends FunctionLikeChecker
{
    /**
     * @param StatementsSource              $source
     */
    public function __construct(PhpParser\Node\Stmt\Function_ $function, StatementsSource $source)
    {
        parent::__construct($function, $source);
    }

    /**
     * @param  string                      $function_id
     * @param  array<PhpParser\Node\Arg>   $call_args
     * @param  CodeLocation                $code_location
     * @param  array                       $suppressed_issues
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMapWithArgs(
        StatementsChecker $statements_checker,
        $function_id,
        array $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $call_map_key = strtolower($function_id);

        $call_map = CallMap::getCallMap();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        switch ($call_map_key) {
            case 'getenv':
                if (!empty($call_args)) {
                    return new Type\Union([new Type\Atomic\TString, new Type\Atomic\TFalse]);
                }

                return new Type\Union([new Type\Atomic\TArray([Type::getMixed(), Type::getString()])]);
        }

        if ($call_args) {
            switch ($call_map_key) {
                case 'str_replace':
                case 'str_ireplace':
                case 'substr_replace':
                case 'preg_replace':
                case 'preg_replace_callback':
                    if (isset($call_args[2]->value->inferredType)) {
                        $subject_type = $call_args[2]->value->inferredType;

                        if (!$subject_type->hasString() && $subject_type->hasArray()) {
                            return Type::getArray();
                        }

                        $return_type = Type::getString();

                        if (in_array($call_map_key, ['preg_replace', 'preg_replace_callback'], true)) {
                            $return_type->addType(new Type\Atomic\TNull());
                            $return_type->ignore_nullable_issues = true;
                        }

                        return $return_type;
                    }

                    return Type::getMixed();

                case 'pathinfo':
                    if (isset($call_args[1])) {
                        return Type::getString();
                    }

                    return Type::getArray();

                case 'count':
                    if (isset($call_args[0]->value->inferredType)) {
                        $atomic_types = $call_args[0]->value->inferredType->getTypes();

                        if (count($atomic_types) === 1 && isset($atomic_types['array'])) {
                            if ($atomic_types['array'] instanceof Type\Atomic\TArray) {
                                return new Type\Union([
                                    $atomic_types['array']->count !== null
                                        ? new Type\Atomic\TLiteralInt($atomic_types['array']->count)
                                        : new Type\Atomic\TInt
                                ]);
                            } elseif ($atomic_types['array'] instanceof Type\Atomic\ObjectLike
                                && $atomic_types['array']->sealed
                            ) {
                                return new Type\Union([
                                    new Type\Atomic\TLiteralInt(count($atomic_types['array']->properties))
                                ]);
                            }
                        }
                    }

                    break;

                case 'var_export':
                case 'highlight_string':
                case 'highlight_file':
                    if (isset($call_args[1]->value->inferredType)) {
                        $subject_type = $call_args[1]->value->inferredType;

                        if ((string) $subject_type === 'true') {
                            return Type::getString();
                        }

                        return new Type\Union([
                            new Type\Atomic\TString,
                            $call_map_key === 'var_export' ? new Type\Atomic\TNull : new Type\Atomic\TBool
                        ]);
                    }

                    return $call_map_key === 'var_export' ? Type::getVoid() : Type::getBool();

                case 'array_map':
                    return self::getArrayMapReturnType(
                        $statements_checker,
                        $call_args,
                        $code_location,
                        $suppressed_issues
                    );

                case 'array_filter':
                    return self::getArrayFilterReturnType(
                        $statements_checker,
                        $call_args,
                        $code_location,
                        $suppressed_issues
                    );

                case 'array_merge':
                    return self::getArrayMergeReturnType($call_args);

                case 'array_rand':
                    return self::getArrayRandReturnType($call_args);

                case 'explode':
                    if ($call_args[0]->value instanceof PhpParser\Node\Scalar\String_) {
                        if ($call_args[0]->value->value === '') {
                            return Type::getFalse();
                        }

                        return new Type\Union([
                            new Type\Atomic\TArray([
                                Type::getInt(),
                                Type::getString()
                            ])
                        ]);
                    }

                    break;

                case 'iterator_to_array':
                    if (isset($call_args[0]->value->inferredType)
                        && $call_args[0]->value->inferredType->hasObjectType()
                    ) {
                        $value_type = null;

                        foreach ($call_args[0]->value->inferredType->getTypes() as $call_arg_atomic_type) {
                            if ($call_arg_atomic_type instanceof Type\Atomic\TGenericObject) {
                                $type_params = $call_arg_atomic_type->type_params;
                                $last_param_type = $type_params[count($type_params) - 1];

                                $value_type = $value_type
                                    ? Type::combineUnionTypes($value_type, $last_param_type)
                                    : $last_param_type;
                            }
                        }

                        if ($value_type) {
                            return new Type\Union([
                                new Type\Atomic\TArray([
                                    Type::getMixed(),
                                    $value_type
                                ])
                            ]);
                        }
                    }

                    break;

                case 'array_column':
                    $row_shape = null;
                    // calculate row shape
                    if (isset($call_args[0]->value->inferredType)
                        && $call_args[0]->value->inferredType->isSingle()
                        && $call_args[0]->value->inferredType->hasArray()
                    ) {
                        $input_array = $call_args[0]->value->inferredType->getTypes()['array'];
                        if ($input_array instanceof Type\Atomic\ObjectLike) {
                            $row_type = $input_array->getGenericArrayType()->type_params[1];
                            if ($row_type->isSingle() && $row_type->hasArray()) {
                                $row_shape = $row_type->getTypes()['array'];
                            }
                        } elseif ($input_array instanceof Type\Atomic\TArray) {
                            $row_type = $input_array->type_params[1];
                            if ($row_type->isSingle() && $row_type->hasArray()) {
                                $row_shape = $row_type->getTypes()['array'];
                            }
                        }
                    }

                    $value_column_name = null;
                    // calculate value column name
                    if (isset($call_args[1]->value->inferredType)) {
                        $value_column_name_arg= $call_args[1]->value->inferredType;
                        if ($value_column_name_arg->isSingleIntLiteral()) {
                            $value_column_name = $value_column_name_arg->getSingleIntLiteral();
                        } elseif ($value_column_name_arg->isSingleStringLiteral()) {
                            $value_column_name = $value_column_name_arg->getSingleStringLiteral();
                        }
                    }

                    $key_column_name = null;
                    // calculate key column name
                    if (isset($call_args[2]->value->inferredType)) {
                        $key_column_name_arg = $call_args[2]->value->inferredType;
                        if ($key_column_name_arg->isSingleIntLiteral()) {
                            $key_column_name = $key_column_name_arg->getSingleIntLiteral();
                        } elseif ($key_column_name_arg->isSingleStringLiteral()) {
                            $key_column_name = $key_column_name_arg->getSingleStringLiteral();
                        }
                    }

                    $result_key_type = Type::getMixed();
                    $result_element_type = null;
                    // calculate results
                    if ($row_shape instanceof Type\Atomic\ObjectLike) {
                        if ((null !== $value_column_name) && isset($row_shape->properties[$value_column_name])) {
                            $result_element_type = $row_shape->properties[$value_column_name];
                        } else {
                            $result_element_type = Type::getMixed();
                        }

                        if ((null !== $key_column_name) && isset($row_shape->properties[$key_column_name])) {
                            $result_key_type = $row_shape->properties[$key_column_name];
                        }
                    }

                    if ($result_element_type) {
                        return new Type\Union([
                            new Type\Atomic\TArray([
                                $result_key_type,
                                $result_element_type
                            ])
                        ]);
                    }
                    break;

                case 'abs':
                    if (isset($call_args[0]->value)) {
                        $first_arg = $call_args[0]->value;

                        if (isset($first_arg->inferredType)) {
                            $numeric_types = [];

                            foreach ($first_arg->inferredType->getTypes() as $inner_type) {
                                if ($inner_type->isNumericType()) {
                                    $numeric_types[] = $inner_type;
                                }
                            }

                            if ($numeric_types) {
                                return new Type\Union($numeric_types);
                            }
                        }
                    }

                    break;

                case 'min':
                case 'max':
                    if (isset($call_args[0])) {
                        $first_arg = $call_args[0]->value;

                        if (isset($first_arg->inferredType)) {
                            if ($first_arg->inferredType->hasArray()) {
                                $array_type = $first_arg->inferredType->getTypes()['array'];
                                if ($array_type instanceof Type\Atomic\ObjectLike) {
                                    return $array_type->getGenericValueType();
                                }

                                if ($array_type instanceof Type\Atomic\TArray) {
                                    return clone $array_type->type_params[1];
                                }
                            } elseif ($first_arg->inferredType->hasScalarType() &&
                                ($second_arg = $call_args[1]->value) &&
                                isset($second_arg->inferredType) &&
                                $second_arg->inferredType->hasScalarType()
                            ) {
                                return Type::combineUnionTypes($first_arg->inferredType, $second_arg->inferredType);
                            }
                        }
                    }

                    break;
            }
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        $call_map_return_type = Type::parseString($call_map[$call_map_key][0]);

        switch ($call_map_key) {
            case 'mb_strpos':
            case 'mb_strrpos':
            case 'mb_stripos':
            case 'mb_strripos':
            case 'strpos':
            case 'strrpos':
            case 'stripos':
            case 'strripos':
                break;

            default:
                if ($call_map_return_type->isFalsable()) {
                    $call_map_return_type->ignore_falsable_issues = true;
                }
        }

        return $call_map_return_type;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     *
     * @return Type\Union
     */
    private static function getArrayMergeReturnType(array $call_args)
    {
        $inner_value_types = [];
        $inner_key_types = [];

        $generic_properties = [];

        foreach ($call_args as $call_arg) {
            if (!isset($call_arg->value->inferredType)) {
                return Type::getArray();
            }

            foreach ($call_arg->value->inferredType->getTypes() as $type_part) {
                if ($call_arg->unpack) {
                    if (!$type_part instanceof Type\Atomic\TArray) {
                        if ($type_part instanceof Type\Atomic\ObjectLike) {
                            $type_part_value_type = $type_part->getGenericValueType();
                        } else {
                            return Type::getArray();
                        }
                    } else {
                        $type_part_value_type = $type_part->type_params[1];
                    }

                    $unpacked_type_parts = [];

                    foreach ($type_part_value_type->getTypes() as $value_type_part) {
                        $unpacked_type_parts[] = $value_type_part;
                    }
                } else {
                    $unpacked_type_parts = [$type_part];
                }

                foreach ($unpacked_type_parts as $unpacked_type_part) {
                    if (!$unpacked_type_part instanceof Type\Atomic\TArray) {
                        if ($unpacked_type_part instanceof Type\Atomic\ObjectLike) {
                            if ($generic_properties !== null) {
                                $generic_properties = array_merge(
                                    $generic_properties,
                                    $unpacked_type_part->properties
                                );
                            }

                            $unpacked_type_part = $unpacked_type_part->getGenericArrayType();
                        } else {
                            if ($unpacked_type_part instanceof Type\Atomic\TMixed
                                && $unpacked_type_part->from_isset
                            ) {
                                $unpacked_type_part = new Type\Atomic\TArray([
                                    Type::getMixed(),
                                    Type::getMixed(true)
                                ]);
                            } else {
                                return Type::getArray();
                            }
                        }
                    } elseif (!$unpacked_type_part->type_params[0]->isEmpty()) {
                        $generic_properties = null;
                    }

                    if ($unpacked_type_part->type_params[1]->isEmpty()) {
                        continue;
                    }

                    $inner_key_types = array_merge(
                        $inner_key_types,
                        array_values($unpacked_type_part->type_params[0]->getTypes())
                    );
                    $inner_value_types = array_merge(
                        $inner_value_types,
                        array_values($unpacked_type_part->type_params[1]->getTypes())
                    );
                }
            }
        }

        if ($generic_properties) {
            return new Type\Union([
                new Type\Atomic\ObjectLike($generic_properties),
            ]);
        }

        if ($inner_value_types) {
            return new Type\Union([
                new Type\Atomic\TArray([
                    TypeCombination::combineTypes($inner_key_types),
                    TypeCombination::combineTypes($inner_value_types),
                ]),
            ]);
        }

        return Type::getArray();
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     *
     * @return Type\Union
     */
    private static function getArrayRandReturnType(array $call_args)
    {
        $first_arg = isset($call_args[0]->value) ? $call_args[0]->value : null;
        $second_arg = isset($call_args[1]->value) ? $call_args[1]->value : null;

        $first_arg_array = $first_arg
            && isset($first_arg->inferredType)
            && $first_arg->inferredType->hasType('array')
            && ($array_atomic_type = $first_arg->inferredType->getTypes()['array'])
            && ($array_atomic_type instanceof Type\Atomic\TArray ||
                $array_atomic_type instanceof Type\Atomic\ObjectLike)
        ? $array_atomic_type
        : null;

        if (!$first_arg_array) {
            return Type::getMixed();
        }

        if ($first_arg_array instanceof Type\Atomic\TArray) {
            $key_type = clone $first_arg_array->type_params[0];
        } else {
            $key_type = $first_arg_array->getGenericKeyType();
        }

        if (!$second_arg
            || ($second_arg instanceof PhpParser\Node\Scalar\LNumber && $second_arg->value === 1)
        ) {
            return $key_type;
        }

        $arr_type = new Type\Union([
            new Type\Atomic\TArray([
                Type::getInt(),
                $key_type,
            ]),
        ]);

        if ($second_arg instanceof PhpParser\Node\Scalar\LNumber) {
            return $arr_type;
        }

        return Type::combineUnionTypes($key_type, $arr_type);
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     *
     * @return Type\Union
     */
    private static function getArrayMapReturnType(
        StatementsChecker $statements_checker,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $array_arg = isset($call_args[1]->value) ? $call_args[1]->value : null;

        $array_arg_type = null;

        if ($array_arg && isset($array_arg->inferredType)) {
            $arg_types = $array_arg->inferredType->getTypes();

            if (isset($arg_types['array'])
                && ($arg_types['array'] instanceof Type\Atomic\TArray
                    || $arg_types['array'] instanceof Type\Atomic\ObjectLike)
            ) {
                $array_arg_type = $arg_types['array'];
            }
        }

        if (isset($call_args[0])) {
            $function_call_arg = $call_args[0];

            if (count($call_args) === 2) {
                if ($array_arg_type instanceof Type\Atomic\ObjectLike) {
                    $generic_key_type = $array_arg_type->getGenericKeyType();
                } else {
                    $generic_key_type = $array_arg_type ? clone $array_arg_type->type_params[0] : Type::getMixed();
                }
            } else {
                $generic_key_type = Type::getInt();
            }

            if ($function_call_arg->value instanceof PhpParser\Node\Expr\Closure
                && isset($function_call_arg->value->inferredType)
                && ($closure_atomic_type = $function_call_arg->value->inferredType->getTypes()['Closure'])
                && $closure_atomic_type instanceof Type\Atomic\Fn
            ) {
                $closure_return_type = $closure_atomic_type->return_type ?: Type::getMixed();

                if ($closure_return_type->isVoid()) {
                    IssueBuffer::accepts(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to array_map',
                            $code_location
                        ),
                        $suppressed_issues
                    );

                    return Type::getArray();
                }

                $inner_type = clone $closure_return_type;

                if ($array_arg_type instanceof Type\Atomic\ObjectLike && count($call_args) === 2) {
                    return new Type\Union([
                        new Type\Atomic\ObjectLike(
                            array_map(
                                /**
                                 * @return Type\Union
                                 */
                                function (Type\Union $_) use ($inner_type) {
                                    return clone $inner_type;
                                },
                                $array_arg_type->properties
                            )
                        ),
                    ]);
                }

                return new Type\Union([
                    new Type\Atomic\TArray([
                        $generic_key_type,
                        $inner_type,
                    ]),
                ]);
            } elseif ($function_call_arg->value instanceof PhpParser\Node\Scalar\String_
                || $function_call_arg->value instanceof PhpParser\Node\Expr\Array_
            ) {
                $mapping_function_ids = Statements\Expression\CallChecker::getFunctionIdsFromCallableArg(
                    $statements_checker,
                    $function_call_arg->value
                );

                $call_map = CallMap::getCallMap();

                $mapping_return_type = null;

                $project_checker = $statements_checker->getFileChecker()->project_checker;
                $codebase = $project_checker->codebase;

                foreach ($mapping_function_ids as $mapping_function_id) {
                    $mapping_function_id = strtolower($mapping_function_id);

                    $mapping_function_id_parts = explode('&', $mapping_function_id);

                    $part_match_found = false;

                    foreach ($mapping_function_id_parts as $mapping_function_id_part) {
                        if (isset($call_map[$mapping_function_id_part][0])) {
                            if ($call_map[$mapping_function_id_part][0]) {
                                $mapped_function_return =
                                    Type::parseString($call_map[$mapping_function_id_part][0]);

                                if ($mapping_return_type) {
                                    $mapping_return_type = Type::combineUnionTypes(
                                        $mapping_return_type,
                                        $mapped_function_return
                                    );
                                } else {
                                    $mapping_return_type = $mapped_function_return;
                                }

                                $part_match_found = true;
                            }
                        } else {
                            if (strpos($mapping_function_id_part, '::') !== false) {
                                list($callable_fq_class_name) = explode('::', $mapping_function_id_part);

                                if (in_array($callable_fq_class_name, ['self', 'static', 'parent'], true)) {
                                    continue;
                                }

                                if (!$codebase->methodExists($mapping_function_id_part)) {
                                    continue;
                                }

                                $part_match_found = true;

                                $self_class = 'self';

                                $return_type = $codebase->methods->getMethodReturnType(
                                    $mapping_function_id_part,
                                    $self_class
                                ) ?: Type::getMixed();

                                if ($mapping_return_type) {
                                    $mapping_return_type = Type::combineUnionTypes(
                                        $mapping_return_type,
                                        $return_type
                                    );
                                } else {
                                    $mapping_return_type = $return_type;
                                }
                            } else {
                                if (!$codebase->functions->functionExists(
                                    $statements_checker,
                                    $mapping_function_id_part
                                )) {
                                    $mapping_return_type = Type::getMixed();
                                    continue;
                                }

                                $part_match_found = true;

                                $function_storage = $codebase->functions->getStorage(
                                    $statements_checker,
                                    $mapping_function_id_part
                                );

                                $return_type = $function_storage->return_type ?: Type::getMixed();

                                if ($mapping_return_type) {
                                    $mapping_return_type = Type::combineUnionTypes(
                                        $mapping_return_type,
                                        $return_type
                                    );
                                } else {
                                    $mapping_return_type = $return_type;
                                }
                            }
                        }
                    }

                    if ($part_match_found === false) {
                        $mapping_return_type = Type::getMixed();
                    }
                }

                if ($mapping_return_type) {
                    if ($array_arg_type instanceof Type\Atomic\ObjectLike && count($call_args) === 2) {
                        return new Type\Union([
                            new Type\Atomic\ObjectLike(
                                array_map(
                                    /**
                                     * @return Type\Union
                                     */
                                    function (Type\Union $_) use ($mapping_return_type) {
                                        return clone $mapping_return_type;
                                    },
                                    $array_arg_type->properties
                                )
                            ),
                        ]);
                    }

                    return new Type\Union([
                        new Type\Atomic\TArray([
                            $generic_key_type,
                            $mapping_return_type,
                        ]),
                    ]);
                }
            }
        }

        return Type::getArray();
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     *
     * @return Type\Union
     */
    private static function getArrayFilterReturnType(
        StatementsChecker $statements_checker,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $array_arg = isset($call_args[0]->value) ? $call_args[0]->value : null;

        $first_arg_array = $array_arg
            && isset($array_arg->inferredType)
            && $array_arg->inferredType->hasType('array')
            && ($array_atomic_type = $array_arg->inferredType->getTypes()['array'])
            && ($array_atomic_type instanceof Type\Atomic\TArray ||
                $array_atomic_type instanceof Type\Atomic\ObjectLike)
            ? $array_atomic_type
            : null;

        if (!$first_arg_array) {
            return Type::getArray();
        }

        if ($first_arg_array instanceof Type\Atomic\TArray) {
            $inner_type = $first_arg_array->type_params[1];
            $key_type = clone $first_arg_array->type_params[0];
        } else {
            $inner_type = $first_arg_array->getGenericValueType();
            $key_type = $first_arg_array->getGenericKeyType();
        }

        if (!isset($call_args[1])) {
            $inner_type->removeType('null');
            $inner_type->removeType('false');
        } elseif (!isset($call_args[2])) {
            $function_call_arg = $call_args[1];

            if ($function_call_arg->value instanceof PhpParser\Node\Expr\Closure
                && isset($function_call_arg->value->inferredType)
                && ($closure_atomic_type = $function_call_arg->value->inferredType->getTypes()['Closure'])
                && $closure_atomic_type instanceof Type\Atomic\Fn
            ) {
                $closure_return_type = $closure_atomic_type->return_type ?: Type::getMixed();

                if ($closure_return_type->isVoid()) {
                    IssueBuffer::accepts(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to array_filter',
                            $code_location
                        ),
                        $suppressed_issues
                    );

                    return Type::getArray();
                }

                if (count($function_call_arg->value->stmts) === 1 && count($function_call_arg->value->params)) {
                    $first_param = $function_call_arg->value->params[0];
                    $stmt = $function_call_arg->value->stmts[0];

                    if ($first_param->variadic === false
                        && $first_param->var instanceof PhpParser\Node\Expr\Variable
                        && is_string($first_param->var->name)
                        && $stmt instanceof PhpParser\Node\Stmt\Return_
                        && $stmt->expr
                    ) {
                        $assertions = AssertionFinder::getAssertions($stmt->expr, null, $statements_checker);

                        if (isset($assertions['$' . $first_param->var->name])) {
                            $changed_var_ids = [];

                            $reconciled_types = Reconciler::reconcileKeyedTypes(
                                ['$inner_type' => [[$assertions['$' . $first_param->var->name]]]],
                                ['$inner_type' => $inner_type],
                                $changed_var_ids,
                                ['$inner_type' => true],
                                $statements_checker,
                                new CodeLocation($statements_checker->getSource(), $stmt),
                                $statements_checker->getSuppressedIssues()
                            );

                            if (isset($reconciled_types['$inner_type'])) {
                                $inner_type = $reconciled_types['$inner_type'];
                            }
                        }
                    }
                }
            }

            return new Type\Union([
                new Type\Atomic\TArray([
                    $key_type,
                    $inner_type,
                ]),
            ]);
        }

        return new Type\Union([
            new Type\Atomic\TArray([
                $key_type,
                $inner_type,
            ]),
        ]);
    }
}

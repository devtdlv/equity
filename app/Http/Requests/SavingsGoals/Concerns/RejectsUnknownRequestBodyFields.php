<?php

namespace App\Http\Requests\SavingsGoals\Concerns;

use Illuminate\Validation\Validator;

/**
 * Rejects unrecognized JSON/request-body keys while ignoring query-string parameters.
 */
trait RejectsUnknownRequestBodyFields
{
    /**
     * @param  list<string>  $allowed
     */
    protected function rejectUnknownBodyFields(Validator $validator, array $allowed): void
    {
        // FormRequest extends Request; $this->request is the body ParameterBag only
        // (query-string keys live on $this->query and are intentionally ignored).
        $bodyKeys = array_keys($this->request->all());
        $unknown = array_values(array_diff($bodyKeys, $allowed));

        foreach ($unknown as $field) {
            $validator->errors()->add(
                $field,
                "The {$field} field is not allowed.",
            );
        }
    }
}

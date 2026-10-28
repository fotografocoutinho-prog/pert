<?php

namespace App\Http\Requests;

use App\Rules\ValidPortugueseNif;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isPortugal = strtoupper((string) $this->input('country_code', 'PT')) === 'PT';

        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'nif' => array_filter(['nullable', 'string', 'max:20', $isPortugal ? new ValidPortugueseNif : null]),
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()\-]{6,30}$/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => array_filter(['nullable', 'string', 'max:20', $isPortugal ? 'regex:/^\d{4}-\d{3}$/' : null]),
            'city' => ['nullable', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome comercial',
            'legal_name' => 'denominação social',
            'postal_code' => 'código postal',
            'address_line1' => 'morada',
            'country_code' => 'país',
        ];
    }

    public function messages(): array
    {
        return [
            'postal_code.regex' => 'O código postal deve ter o formato 0000-000.',
            'phone.regex' => 'O telefone só pode conter números, espaços e os símbolos + ( ) -.',
        ];
    }
}

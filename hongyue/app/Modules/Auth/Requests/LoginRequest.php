<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 账号密码登录请求
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_no' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:64'],
            'device' => ['sometimes', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_no.required' => '请输入工号',
            'password.required' => '请输入密码',
        ];
    }
}
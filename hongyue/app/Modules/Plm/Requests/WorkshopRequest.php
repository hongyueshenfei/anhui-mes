<?php

namespace App\Modules\Plm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 车间创建 / 更新请求
 */
class WorkshopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:32',
                Rule::unique('workshops', 'code')->ignore($id)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:64'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'integer', 'in:0,1'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '请输入车间编码',
            'code.unique' => '车间编码已存在',
            'name.required' => '请输入车间名称',
        ];
    }
}

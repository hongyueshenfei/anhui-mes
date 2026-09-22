<?php

namespace App\Modules\System\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 用户创建/更新请求
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'employee_no' => [
                'required', 'string', 'max:32',
                'unique:users,employee_no'.($userId ? ','.$userId : ''),
            ],
            'name' => ['required', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:6', 'max:64'],
            'wecom_userid' => ['nullable', 'string', 'max:64', 'unique:users,wecom_userid'.($userId ? ','.$userId : '')],
            'wx_openid' => ['nullable', 'string', 'max:64', 'unique:users,wx_openid'.($userId ? ','.$userId : '')],
            'workshop_id' => ['nullable', 'integer', 'exists:workshops,id'],
            'job_type' => ['sometimes', 'in:worker,leader,statistician,planner,engineer,sales,admin,boss'],
            'status' => ['sometimes', 'in:0,1'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_no.required' => '请输入工号',
            'employee_no.unique' => '工号已存在',
            'name.required' => '请输入姓名',
            'job_type.in' => '岗位类型不合法',
            'workshop_id.exists' => '车间不存在',
            'roles.*.exists' => '角色不存在',
        ];
    }
}

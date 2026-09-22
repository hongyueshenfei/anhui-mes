<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 绑定工号请求（企微/微信首次扫码后）
 * 请求体：{channel: wecom|wx, code(企微)|openid(微信), employee_no, password?}
 */
class BindEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channel = $this->input('channel', 'wx');

        return [
            'channel' => ['required', 'in:wecom,wx'],
            'employee_no' => ['required', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:6', 'max:64'],
            // 企微通道传 code；微信通道传 openid（两者取其一即可）
            'code' => ['required_if:channel,wecom', 'string'],
            'openid' => ['required_if:channel,wx', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'channel.required' => '请指定绑定通道',
            'channel.in' => '绑定通道仅支持 wecom / wx',
            'employee_no.required' => '请输入工号',
            'code.required_if' => '缺少企微授权码',
            'openid.required_if' => '缺少微信 openid',
        ];
    }

    protected function prepareForValidation(): void
    {
        // 兼容：微信通道时前端可能用 code 字段传 openid
        if ($this->input('channel') === 'wx' && ! $this->has('openid') && $this->has('code')) {
            $this->merge(['openid' => $this->input('code')]);
        }
    }
}
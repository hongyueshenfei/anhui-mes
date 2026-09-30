<?php

namespace App\Modules\Production\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 报工请求校验（正常/冲减/代报/离线补传）
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'exists:prod_tasks,id'],
            'qty' => ['required', 'integer', 'not_in:0'],
            'defect_qty' => ['nullable', 'integer'],
            'correction_of_report_id' => ['nullable', 'integer', 'exists:work_reports,id'],
            'worker_id' => ['nullable', 'integer', 'exists:users,id'],
            'schedule_id' => ['nullable', 'integer', 'exists:task_schedules,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'source' => ['nullable', 'in:scan,click,leader_report,leader_batch,machine_qr,makeup,offline'],
            'offline_flag' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
            'report_time' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date', 'after_or_equal:start_time'],
            'work_date' => ['nullable', 'date_format:Y-m-d'],
            'shift_code' => ['nullable', 'string', 'max:16'],
            'client_request_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'qty.required' => '报工数量必填',
            'qty.not_in' => '报工数量不能为 0（冲减请填负数）',
            'task_id.exists' => '任务不存在',
            'end_time.after_or_equal' => '结束时间不能早于开始时间',
        ];
    }
}

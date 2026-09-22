import axios from 'axios'

/**
 * 车间 H5 axios 实例（对接 Laravel /api）
 */
const http = axios.create({
  // 空 baseURL：各 API 模块使用完整前缀（/api 或 /mobile），vite 代理转发到 Laravel
  baseURL: '',
  timeout: 15000,
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('hy_mobile_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

http.interceptors.response.use(
  (response) => {
    const body = response.data
    if (body && body.code !== 0) {
      return Promise.reject(new Error(body.message || '请求失败'))
    }
    return response
  },
  (error) => Promise.reject(error),
)

export default http
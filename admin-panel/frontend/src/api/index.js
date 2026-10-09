import axios from 'axios';

// 生产：页面与接口同域，接口经 Nginx /server/ 转发到后端
// 本地 dev：vite 把 /server 代理到本地后端
const api = axios.create({ baseURL: '/server/api' });

api.interceptors.request.use((cfg) => {
  const token = localStorage.getItem('token');
  if (token) cfg.headers.Authorization = `Bearer ${token}`;
  return cfg;
});

api.interceptors.response.use(
  (res) => res.data,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('token');
      if (location.hash !== '#/login') location.hash = '#/login';
    }
    return Promise.reject(err.response?.data || { message: err.message });
  }
);

export default api;

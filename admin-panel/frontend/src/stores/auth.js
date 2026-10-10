import { defineStore } from 'pinia';
import api from '../api';
import { startWs, stopWs } from '../ws';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('token') || '',
    me: null,
  }),
  getters: {
    permissions: (s) => s.me?.permissions || [],
    has: (s) => (code) => s.me?.permissions.includes(code) || false,
  },
  actions: {
    async login(username, password) {
      const { token } = await api.post('/auth/login', { username, password });
      this.token = token;
      localStorage.setItem('token', token);
      await this.fetchMe();
      startWs();
    },
    async fetchMe() {
      this.me = await api.get('/me');
    },
    logout() {
      this.token = '';
      this.me = null;
      localStorage.removeItem('token');
      stopWs();
    },
  },
});

import { api } from './api/client';
import type { AuthSession } from '@/types/models';
import type { ForgotPasswordPayload, ForgotPasswordResult, LoginPayload, ResetPasswordPayload } from '@/types/api';

export const authService = {
  login: (payload: LoginPayload) => api.post<AuthSession>('/auth/login', payload),
  logout: () => api.post<null>('/auth/logout'),
  /** Re-validates the stored token and returns fresh user + permissions. */
  me: () => api.get<AuthSession>('/auth/me'),
  forgotPassword: (payload: ForgotPasswordPayload) => api.post<ForgotPasswordResult>('/auth/forgot-password', payload),
  resetPassword: (payload: ResetPasswordPayload) => api.post<{ message: string }>('/auth/reset-password', payload),
};

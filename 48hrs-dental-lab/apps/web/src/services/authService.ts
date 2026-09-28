import { api } from './api/client';
import type { AuthSession } from '@48hrs/shared/types';
import type { ForgotPasswordPayload, ForgotPasswordResult, LoginPayload, ResetPasswordPayload } from '@48hrs/shared/types';

export const authService = {
  login: (payload: LoginPayload) => api.post<AuthSession>('/auth/login', payload),
  logout: () => api.post<null>('/auth/logout'),
  /** Re-validates the session and returns fresh user + permissions. */
  me: () => api.get<AuthSession>('/auth/me'),
  /** Rotates the refresh cookie and issues a new access token (the API client calls this on a 401). */
  refresh: () => api.post<AuthSession>('/auth/refresh'),
  forgotPassword: (payload: ForgotPasswordPayload) => api.post<ForgotPasswordResult>('/auth/forgot-password', payload),
  resetPassword: (payload: ResetPasswordPayload) => api.post<{ message: string }>('/auth/reset-password', payload),
};

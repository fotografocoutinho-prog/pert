import type { ISODateString, UUID } from './common.js';

export type UserRole = 'admin' | 'operator' | 'client';

export interface User {
  id: UUID;
  tenantId: UUID;
  email: string;
  name: string;
  role: UserRole;
  active: boolean;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresIn: number;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  user: User;
  tokens: AuthTokens;
}

export interface JwtPayload {
  sub: UUID;
  tenantId: UUID;
  role: UserRole;
  email: string;
}

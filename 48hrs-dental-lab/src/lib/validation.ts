import { z } from 'zod';

/** Shared Zod building blocks so every form speaks with the same voice. */
export const requiredText = (label: string, max = 120) =>
  z.string().trim().min(1, `${label} is required.`).max(max, `${label} must be ${max} characters or fewer.`);

export const optionalText = (max = 500) => z.string().trim().max(max, `Keep this under ${max} characters.`).optional().or(z.literal(''));

export const emailField = z.string().trim().min(1, 'Email is required.').email('Enter a valid email address.');
export const optionalEmail = z.string().trim().email('Enter a valid email address.').optional().or(z.literal(''));

const PHONE = /^[+\d][\d\s()-]{5,}$/;
export const phoneField = z.string().trim().min(1, 'Phone is required.').regex(PHONE, 'Enter a valid phone number.');
export const optionalPhone = z.string().trim().regex(PHONE, 'Enter a valid phone number.').optional().or(z.literal(''));

export const passwordSchema = z
  .string()
  .min(8, 'Use at least 8 characters.')
  .regex(/[A-Za-z]/, 'Include at least one letter.')
  .regex(/\d/, 'Include at least one number.');

export const moneyField = (label = 'Amount') =>
  z.coerce.number({ invalid_type_error: `${label} must be a number.` }).min(0, `${label} cannot be negative.`);

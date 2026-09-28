/**
 * Outgoing e-mail. MAIL_TRANSPORT=smtp sends through SMTP_URL; "log" (development
 * only — refused in production by config) writes the message to the log instead.
 */
import { createTransport, type Transporter } from 'nodemailer';
import { env } from '../config/env.ts';
import { logger } from './logger.ts';

export interface Mail {
  to: string;
  subject: string;
  text: string;
}

let transporter: Transporter | null = null;

export async function sendMail(mail: Mail) {
  if (env.MAIL_TRANSPORT === 'log') {
    logger.info({ mail: { to: mail.to, subject: mail.subject, text: mail.text } }, 'mail (log transport)');
    return;
  }
  transporter ??= createTransport(env.SMTP_URL);
  await transporter.sendMail({ from: env.MAIL_FROM, to: mail.to, subject: mail.subject, text: mail.text });
}

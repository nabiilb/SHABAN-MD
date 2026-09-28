/**
 * Express application: security headers, CORS for the web origin(s), cookie
 * parsing, body limits, CSRF guard, rate limiting, the /api router and the
 * error contract. Optionally serves the built web app from the same origin.
 */
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import cookieParser from 'cookie-parser';
import cors from 'cors';
import express from 'express';
import helmet from 'helmet';
import { pinoHttp } from 'pino-http';
import { API_ROOT, env, isTest } from './config/env.ts';
import { logger } from './lib/logger.ts';
import { csrfGuard } from './middleware/csrf.ts';
import { errorHandler, notFoundHandler } from './middleware/error-handler.ts';
import { apiLimiter } from './middleware/rate-limit.ts';
import { apiRouter } from './routes/index.ts';

export function createApp() {
  const app = express();
  app.disable('x-powered-by');
  app.set('trust proxy', env.TRUST_PROXY);
  app.set('query parser', 'simple');

  if (!isTest) {
    app.use(
      pinoHttp({
        logger,
        autoLogging: { ignore: (req) => req.url === '/api/health' },
        // Paths only: query strings carry search terms (patient names, phone numbers).
        serializers: { req: (req: { id: unknown; method: string; url: string }) => ({ id: req.id, method: req.method, url: req.url.split('?')[0] }), res: (res: { statusCode: number }) => ({ statusCode: res.statusCode }) },
      }),
    );
  }

  // Strict headers for JSON; the SPA (when served here) gets its own CSP below.
  app.use(
    '/api',
    helmet({
      contentSecurityPolicy: { directives: { defaultSrc: ["'none'"], frameAncestors: ["'none'"] } },
      crossOriginResourcePolicy: { policy: 'same-origin' },
    }),
  );
  app.use(
    '/api',
    cors({
      credentials: true,
      origin: (origin, cb) => cb(null, !origin || env.CORS_ORIGINS.includes(origin)),
      allowedHeaders: ['Content-Type', 'X-Requested-With', 'Accept'],
      exposedHeaders: ['X-Server-Time'],
      maxAge: 600,
    }),
  );
  app.use('/api', (_req, res, next) => {
    // Lets the web app align its countdowns with the server clock, the source of truth for deadlines.
    res.setHeader('X-Server-Time', String(Date.now()));
    res.setHeader('Cache-Control', 'no-store');
    next();
  });
  app.use('/api', cookieParser());
  app.use('/api', express.json({ limit: '1mb' }));
  app.use('/api', apiLimiter);
  app.use('/api', csrfGuard);
  app.use('/api', apiRouter());
  app.use('/api', notFoundHandler);

  const webDir = env.WEB_DIST_DIR ? resolve(API_ROOT, env.WEB_DIST_DIR) : null;
  if (webDir && existsSync(join(webDir, 'index.html'))) serveWeb(app, webDir);

  app.use(errorHandler);
  return app;
}

/** Single-origin deployment: the built SPA with a strict CSP and history-API fallback. */
function serveWeb(app: express.Express, dir: string) {
  app.use(
    helmet({
      contentSecurityPolicy: {
        directives: {
          defaultSrc: ["'self'"],
          scriptSrc: ["'self'"],
          styleSrc: ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
          fontSrc: ["'self'", 'https://fonts.gstatic.com', 'data:'],
          imgSrc: ["'self'", 'data:', 'blob:'],
          connectSrc: ["'self'", ...env.CORS_ORIGINS],
          frameSrc: ["'self'", 'blob:'],
          objectSrc: ["'none'"],
          baseUri: ["'self'"],
          formAction: ["'self'"],
          frameAncestors: ["'none'"],
          // Only on HTTPS deployments: on plain-http LAN installs it would block the app's own assets.
          upgradeInsecureRequests: env.COOKIE_SECURE ? [] : null,
        },
      },
    }),
  );
  app.use(express.static(dir, { index: false, maxAge: '1y', immutable: true, setHeaders: (res, path) => path.endsWith('.html') && res.setHeader('Cache-Control', 'no-cache') }));
  app.get(/^(?!\/api\/).*/, (req, res, next) => {
    if (!req.accepts('html')) return next();
    res.setHeader('Cache-Control', 'no-cache');
    res.sendFile(join(dir, 'index.html'));
  });
}


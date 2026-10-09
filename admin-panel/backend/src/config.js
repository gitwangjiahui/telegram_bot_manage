import dotenv from 'dotenv';

dotenv.config();

export const config = {
  port: parseInt(process.env.PORT || '3000', 10),
  jwtSecret: process.env.JWT_SECRET || 'change-me-in-production',
  jwtExpires: process.env.JWT_EXPIRES || '7d',
  db: {
    host: process.env.DB_HOST || '101.200.84.19',
    port: parseInt(process.env.DB_PORT || '3306', 10),
    user: process.env.DB_USER || 'wangjiahui',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'telegram_bot',
  },
  // 容器内调用 Telegram API 的代理（如 http://host.docker.internal:7890），留空直连
  tgProxy: process.env.TG_PROXY || '',
  defaultAdmin: {
    username: process.env.ADMIN_USERNAME || 'admin',
    password: process.env.ADMIN_PASSWORD || 'admin123',
  },
};

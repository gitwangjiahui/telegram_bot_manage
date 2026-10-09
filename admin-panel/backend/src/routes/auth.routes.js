import { Router } from 'express';
import bcrypt from 'bcryptjs';
import jwt from 'jsonwebtoken';
import { config } from '../config.js';
import { one, query } from '../db.js';

const router = Router();

router.post('/login', async (req, res) => {
  const { username, password } = req.body || {};
  if (!username || !password) return res.status(400).json({ message: '请输入账号和密码' });

  const user = await one('SELECT * FROM admin_users WHERE username = ?', [username]);
  if (!user || !user.is_active || !(await bcrypt.compare(password, user.password_hash))) {
    return res.status(401).json({ message: '账号或密码错误' });
  }

  const token = jwt.sign({ sub: user.id, username: user.username }, config.jwtSecret, {
    expiresIn: config.jwtExpires,
  });
  await query('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?', [user.id]);

  res.json({ token });
});

export default router;

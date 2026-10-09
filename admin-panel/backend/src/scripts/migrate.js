import { readFile, readdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import bcrypt from 'bcryptjs';
import { pool } from '../db.js';
import { config } from '../config.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const MIGRATIONS_DIR = join(__dirname, '..', 'migrations');

// mysql2 对多语句 DDL 需开启 multipleStatements；用独立连接
async function runSqlFile(file) {
  const sql = await readFile(file, 'utf8');
  const conn = await pool.getConnection();
  try {
    await conn.query({ sql, multipleStatements: true });
  } finally {
    conn.release();
  }
}

async function ensureDefaultAdmin() {
  const [rows] = await pool.execute('SELECT id FROM admin_users WHERE username = ?', [
    config.defaultAdmin.username,
  ]);
  if (rows.length > 0) return;
  const hash = await bcrypt.hash(config.defaultAdmin.password, 10);
  const conn = await pool.getConnection();
  try {
    const [r] = await conn.query(
      'INSERT INTO admin_users (username, password_hash, real_name) VALUES (?, ?, ?)',
      [config.defaultAdmin.username, hash, '初始管理员']
    );
    await conn.query(
      'INSERT INTO admin_user_roles (user_id, role_id) SELECT ?, id FROM admin_roles WHERE role_code = ?',
      [r.insertId, 'super_admin']
    );
  } finally {
    conn.release();
  }
  console.log(`  默认管理员已创建: ${config.defaultAdmin.username}`);
}

async function syncSuperRolePerms() {
  // 每次迁移后保证超级角色拥有当前全部权限点
  await pool.query(
    `INSERT INTO admin_role_perms (role_id, perm_id)
     SELECT r.id, p.id FROM admin_roles r CROSS JOIN admin_permissions p
     WHERE r.role_code = 'super_admin'
     ON DUPLICATE KEY UPDATE role_id = role_id`
  );
}

async function main() {
  console.log('开始数据库迁移...');
  const files = (await readdir(MIGRATIONS_DIR)).filter((f) => f.endsWith('.sql')).sort();
  for (const f of files) {
    console.log(`  执行 ${f}`);
    await runSqlFile(join(MIGRATIONS_DIR, f));
  }
  await syncSuperRolePerms();
  await ensureDefaultAdmin();
  console.log('迁移完成。');
  await pool.end();
}

main().catch((e) => {
  console.error('迁移失败:', e);
  process.exit(1);
});

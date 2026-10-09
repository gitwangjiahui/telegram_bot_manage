#!/usr/bin/env node
/**
 * 旧消息回填脚本（可选）
 *
 * Longman 库的 message 表不记录“消息属于哪个 bot”，无法可靠归属，
 * 此脚本仅把“管理员聊天中的消息”按 forward_map 尽力匹配回填，
 * 匹配不上的不处理。新消息由 PHP 侧 MessageLog 钩子自动归档，无需本脚本。
 *
 * 用法：
 *   cd backend
 *   node src/scripts/backfill.js            # 预演，只打印数量
 *   node src/scripts/backfill.js --apply    # 实际写入
 */
import { pool } from '../db.js';

const APPLY = process.argv.includes('--apply');

async function main() {
  // forward_map: bot_name + user_id + original_msg_id
  // Longman message 表中用户私聊消息 chat_id = user_id，id = original_msg_id
  const [fwdRows] = await pool.execute(
    `SELECT DISTINCT f.bot_name, f.user_id, f.original_msg_id
       FROM forward_map f`
  );
  console.log(`forward_map 去重消息: ${fwdRows.length} 条`);

  let matched = 0;
  let inserted = 0;
  const BATCH = 200;

  for (let i = 0; i < fwdRows.length; i += BATCH) {
    const batch = fwdRows.slice(i, i + BATCH);
    for (const f of batch) {
      const [msgs] = await pool.execute(
        'SELECT id, text, caption FROM message WHERE chat_id = ? AND id = ? LIMIT 1',
        [f.user_id, f.original_msg_id]
      );
      if (msgs.length === 0) continue;
      matched++;
      if (!APPLY) continue;

      const m = msgs[0];
      const [bots] = await pool.execute('SELECT id FROM bots WHERE bot_name = ?', [f.bot_name]);
      if (bots.length === 0) continue;
      const text = (m.text ?? m.caption ?? null);
      await pool.execute(
        `INSERT INTO message_log
          (bot_id, bot_name, user_id, message_id, direction, sender_id, msg_type, text_content)
         VALUES (?, ?, ?, ?, 'in', ?, 'text', ?)
         ON DUPLICATE KEY UPDATE text_content = VALUES(text_content)`,
        [bots[0].id, f.bot_name, f.user_id, m.id, f.user_id, text]
      );
      inserted++;
    }
    console.log(`  处理 ${Math.min(i + BATCH, fwdRows.length)}/${fwdRows.length}`);
  }

  console.log(`匹配到消息内容: ${matched} 条`);
  console.log(APPLY ? `实际写入: ${inserted} 条` : '（预演模式，加 --apply 实际写入）');
  await pool.end();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

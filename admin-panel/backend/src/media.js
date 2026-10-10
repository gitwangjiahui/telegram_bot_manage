import { fetch } from 'undici';
import { one } from './db.js';
import { getDispatcher } from './proxy.js';

const API = 'https://api.telegram.org';

// 头像 file_id 内存缓存：userId -> fileId|null
const avatarCache = new Map();

// 从 Longman 实体 JSON 字段解析出可展示的媒体描述
export function parseMedia(m) {
  const pick = (jsonStr) => {
    if (!jsonStr) return null;
    try { return JSON.parse(jsonStr); } catch { return null; }
  };

  const photo = pick(m.photo);
  if (Array.isArray(photo) && photo.length) {
    const p = photo[photo.length - 1];
    const thumb = photo[Math.min(1, photo.length - 1)];
    return { type: 'photo', file_id: p.file_id, thumb_id: thumb.file_id,
             width: p.width, height: p.height, size: p.file_size };
  }

  const video = pick(m.video);
  if (video) return { type: 'video', file_id: video.file_id, thumb_id: video.thumb?.file_id,
                      width: video.width, height: video.height, duration: video.duration,
                      mime: video.mime_type, size: video.file_size };

  const animation = pick(m.animation);
  if (animation) return { type: 'animation', file_id: animation.file_id,
                          thumb_id: animation.thumb?.file_id, name: animation.file_name,
                          width: animation.width, height: animation.height, mime: animation.mime_type };

  const videoNote = pick(m.video_note);
  if (videoNote) return { type: 'video_note', file_id: videoNote.file_id,
                          length: videoNote.length, duration: videoNote.duration };

  const voice = pick(m.voice);
  if (voice) return { type: 'voice', file_id: voice.file_id, duration: voice.duration,
                      mime: voice.mime_type };

  const audio = pick(m.audio);
  if (audio) return { type: 'audio', file_id: audio.file_id, duration: audio.duration,
                      mime: audio.mime_type, title: audio.title, performer: audio.performer };

  const doc = pick(m.document);
  if (doc) return { type: 'document', file_id: doc.file_id, name: doc.file_name,
                    mime: doc.mime_type, size: doc.file_size, thumb_id: doc.thumb?.file_id };

  const sticker = pick(m.sticker);
  if (sticker) return { type: 'sticker', file_id: sticker.file_id,
                        animated: !!sticker.is_animated, video: !!sticker.is_video,
                        thumb_id: sticker.thumbnail?.file_id, emoji: sticker.emoji,
                        width: sticker.width, height: sticker.height };

  return null;
}

// 调 getFile 拿路径并下载。返回 { mime, body, size }
// mime 判定优先级：getFilePath 扩展名 > TG 下载响应头 Content-Type > octet-stream
async function downloadFile(token, fileId) {
  const dispatcher = await getDispatcher();
  const res = await fetch(`${API}/bot${token}/getFile`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ file_id: fileId }),
    dispatcher,
  });
  const data = await res.json();
  if (!data.ok) {
    const err = new Error(data.description || 'getFile 失败');
    err.status = 400;
    throw err;
  }
  const filePath = data.result.file_path;
  const ext = (filePath.split('.').pop() || '').toLowerCase();

  const fRes = await fetch(`${API}/file/bot${token}/${filePath}`, { dispatcher });
  if (!fRes.ok) {
    const err = new Error('文件下载失败');
    err.status = 502;
    throw err;
  }
  const mime = EXT_MIME[ext]
    || (fRes.headers['content-type'] && fRes.headers['content-type'].split(';')[0].trim())
    || 'application/octet-stream';
  return { mime, body: fRes.body, size: fRes.headers['content-length'] };
}

const EXT_MIME = {
  jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif',
  webp: 'image/webp', tgs: 'application/x-tgsticker', mp4: 'video/mp4',
  webm: 'video/webm', mp3: 'audio/mpeg', ogg: 'audio/ogg', m4a: 'audio/mp4',
  wav: 'audio/wav', pdf: 'application/pdf',
};

// 按业务媒体类型指定 MIME（TG 对无扩展名文件一律返回 octet-stream，必须由我们纠正）
const TYPE_MIME = {
  photo: 'image/jpeg', sticker: 'image/webp', video: 'video/mp4',
  animation: 'video/mp4', video_note: 'video/mp4', voice: 'audio/ogg',
  audio: 'audio/mpeg', document: 'application/octet-stream',
};

// 通用媒体代理：GET /media/:botId?file_id=xxx&download=1
export async function mediaProxy(req, res) {
  try {
    const botId = Number(req.params.botId);
    const fileId = String(req.query.file_id || '');
    if (!botId || !/^[A-Za-z0-9_-]{8,128}$/.test(fileId)) {
      return res.status(400).json({ message: '参数无效' });
    }
    const bot = await one('SELECT api_key FROM bots WHERE id = ?', [botId]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });

    const { mime, body } = await downloadFile(bot.api_key, fileId);

    // type 查询参数优先（解决 TG file_0 无扩展名问题）
    const finalMime = TYPE_MIME[String(req.query.type || '')] || mime;
    res.setHeader('Content-Type', finalMime);
    res.setHeader('Cache-Control', 'private, max-age=86400');
    if (req.query.download) res.setHeader('Content-Disposition', 'attachment');
    const { Readable } = await import('node:stream');
    Readable.fromWeb(body).pipe(res);
  } catch (e) {
    if (!res.headersSent) res.status(e.status || 502).json({ message: e.message });
  }
}

// 用户头像代理：GET /avatar/:userId?bot_id=xxx
export async function avatarProxy(req, res) {
  try {
    const userId = Number(req.params.userId);
    const botId = Number(req.query.bot_id) || null;
    if (!userId) return res.status(400).json({ message: '参数无效' });

    // 候选机器人：指定 bot > 该用户绑定的 bot > 全部 active bot
    const candidates = [];
    if (botId) {
      const b = await one('SELECT api_key FROM bots WHERE id = ?', [botId]);
      if (b) candidates.push(b);
    }
    candidates.push(...await query(
      `SELECT b.api_key FROM bot_admin_rela r
        JOIN bots b ON b.id = r.bot_id WHERE r.admin_id = ?`, [userId]));
    candidates.push(...await query('SELECT api_key FROM bots WHERE is_active = 1 ORDER BY id LIMIT 3'));
    const seen = new Set();
    const botList = candidates.filter((b) => !seen.has(b.api_key) && seen.add(b.api_key));
    if (!botList.length) return res.status(404).json({ message: '无可用机器人' });

    let fileId = avatarCache.get(userId);
    if (fileId === undefined) {
      fileId = null;
      const dispatcher = await getDispatcher();
      for (const bot of botList) {
        const r = await fetch(`${API}/bot${bot.api_key}/getUserProfilePhotos`, {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ user_id: userId, limit: 1 }),
          dispatcher,
        });
        const data = await r.json();
        if (data.ok && data.result.photos?.[0]?.length) {
          const sizes = data.result.photos[0];
          fileId = sizes[sizes.length - 1].file_id;
          break;
        }
      }
      avatarCache.set(userId, fileId);
    }
    if (!fileId) return res.status(404).json({ message: '无头像' });

    const { mime, body } = await downloadFile(botList[0].api_key, fileId);
    res.setHeader('Content-Type', mime.startsWith('image/') ? mime : 'image/jpeg');
    res.setHeader('Cache-Control', 'private, max-age=86400');
    const { Readable } = await import('node:stream');
    Readable.fromWeb(body).pipe(res);
  } catch (e) {
    if (!res.headersSent) res.status(e.status || 502).json({ message: e.message });
  }
}

<template>
  <div class="media-wrap">
    <!-- 图片 -->
    <el-image v-if="media.type === 'photo'" class="photo" :src="media.url"
              :preview-src-list="[media.url]" preview-teleported fit="cover" />

    <!-- 贴纸（WebP 直接显示，tgs 动画无法渲染，展示 emoji） -->
    <template v-else-if="media.type === 'sticker'">
      <img v-if="!media.animated" class="sticker" :src="media.url" :alt="media.emoji || ''" />
      <div v-else class="tgs-fallback">
        <span class="sticker-emoji">{{ media.emoji || '贴纸' }}</span>
        <span class="sticker-note">动态贴纸</span>
      </div>
    </template>

    <!-- 视频 / GIF / 视频消息 -->
    <template v-else-if="['video','animation','video_note'].includes(media.type)">
      <video class="video" :src="media.url" controls preload="metadata"
             :class="{ round: media.type === 'video_note' }" />
      <div v-if="media.type === 'video_note'" class="vn-note">视频消息 {{ media.duration }}s</div>
    </template>

    <!-- 语音 -->
    <template v-else-if="media.type === 'voice'">
      <div class="voice-box">
        <el-icon class="voice-icon"><Microphone /></el-icon>
        <audio :src="media.url" controls preload="none" class="audio" />
        <span class="duration">{{ media.duration }}″</span>
      </div>
    </template>

    <!-- 音乐 -->
    <template v-else-if="media.type === 'audio'">
      <div class="audio-box">
        <el-icon class="audio-icon"><Headset /></el-icon>
        <div class="audio-info">
          <div class="audio-title">{{ media.title || '音频' }}</div>
          <div class="audio-performer" v-if="media.performer">{{ media.performer }}</div>
        </div>
      </div>
      <audio :src="media.url" controls preload="none" class="audio audio-full" />
    </template>

    <!-- 文件 -->
    <template v-else-if="media.type === 'document'">
      <a class="doc-box" :href="media.url" download>
        <el-icon class="doc-icon"><Document /></el-icon>
        <div class="doc-info">
          <div class="doc-name">{{ media.name || '文件' }}</div>
          <div class="doc-size">{{ formatSize(media.size) }}</div>
        </div>
      </a>
    </template>

    <!-- 其他 -->
    <div v-else class="unknown">
      <el-icon><Link /></el-icon>
      <a :href="media.url">查看附件</a>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({ media: { type: Object, required: true } });

function formatSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}
</script>

<style scoped>
.media-wrap { min-width: 180px; }
.photo {
  max-width: 320px; max-height: 360px; border-radius: 8px; display: block; cursor: zoom-in;
}
.sticker { width: 150px; height: 150px; object-fit: contain; }
.tgs-fallback {
  width: 150px; height: 150px; display: flex; flex-direction: column;
  align-items: center; justify-content: center; background: rgba(0,0,0,0.06); border-radius: 12px;
}
.sticker-emoji { font-size: 52px; }
.sticker-note { font-size: 12px; color: #909399; margin-top: 4px; }
.video { max-width: 320px; max-height: 300px; border-radius: 8px; background: #000; }
.video.round { width: 200px; height: 200px; object-fit: cover; border-radius: 50%; }
.vn-note { font-size: 12px; color: #909399; margin-top: 6px; text-align: center; }
.voice-box { display: flex; align-items: center; gap: 8px; min-width: 240px; }
.voice-icon { font-size: 24px; color: inherit; flex-shrink: 0; }
.audio { height: 36px; flex: 1; }
.duration { font-size: 13px; opacity: 0.8; }
.audio-box { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.audio-icon { font-size: 30px; }
.audio-title { font-weight: 600; font-size: 14px; }
.audio-performer { font-size: 12px; opacity: 0.75; }
.audio-full { width: 280px; }
.doc-box {
  display: flex; align-items: center; gap: 10px; min-width: 220px;
  text-decoration: none; color: inherit;
}
.doc-icon { font-size: 34px; flex-shrink: 0; }
.doc-name { font-weight: 600; font-size: 14px; word-break: break-all; }
.doc-size { font-size: 12px; opacity: 0.7; margin-top: 2px; }
.unknown { display: flex; align-items: center; gap: 6px; }
</style>

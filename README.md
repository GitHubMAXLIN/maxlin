# 安全博客系统

当前版本包含：

- 安装引导 `/install/install.php`
- 后台登录、修改密码、安全退出
- 分类管理
- 文章发布 / 编辑 / 上架 / 下架 / 删除
- 封面图与内容图安全上传
- 富文本白名单清洗
- 标签提取与标签索引
- 百度地图定位字段与小地图选点预留
- 评论开关与评论密码哈希存储
- 点赞 / 踩 IP 哈希限制
- 前台文章列表与详情页

## 已安装项目升级

已经安装过第一阶段后台时，请保留：

```text
app/config/config.php
install.lock
```

然后导入：

```text
database/blog_module.sql
```

详细步骤见：`docs/blog_module_upgrade.md`。

## 安全说明

- 所有状态变更 POST 均校验 CSRF Token 和同源 Origin / Referer。
- 所有 SQL 均使用 PDO 预处理语句，用户输入只通过参数绑定进入 SQL。
- 后台文章、分类、图片等资源操作均带 `user_id` 身份锁。
- 上传图片不信任原文件名和客户端 MIME，使用 `finfo_file`、`exif_imagetype`、`getimagesize` 校验，并用 GD / Imagick 重新编码为 jpg/png/webp。
- 富文本在后端通过白名单清洗，禁止 script、style、iframe、svg、事件属性和危险协议。
- 评论密码使用 `password_hash(..., PASSWORD_ARGON2ID)`，不保存明文。
- 点赞 / 踩不保存明文 IP，只保存 HMAC 哈希。
- 前台文章查询强制 `status = 1 AND deleted_at IS NULL`。

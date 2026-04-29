# 安全自查记录

依据《通用网站安全开发标准 v1.2》第一阶段相关要求，自查如下：

- [x] 所有 POST 请求均校验 CSRF Token。
- [x] 所有状态变更请求校验 Origin / Referer。
- [x] 登录成功后调用 `session_regenerate_id(true)`。
- [x] 修改密码后再次调用 `session_regenerate_id(true)`，并更新 `password_version`。
- [x] 密码使用 Argon2id，不使用 MD5/SHA1/SHA256 明文哈希。
- [x] 登录失败使用统一模糊错误信息。
- [x] 防爆破按账号、IP、IP 段分层统计。
- [x] 连续 3 次失败触发人机验证提示，预留服务端校验接口。
- [x] 连续 5 次以上进入 5/15/60 分钟阶梯式冷却。
- [x] 所有含用户输入的 SQL 均使用 PDO prepare + execute 参数绑定。
- [x] 动态资源归属检查使用后端白名单，不允许前端传表名或列名。
- [x] 会话 Cookie 配置 HttpOnly、SameSite，并支持生产环境 Secure。
- [x] 安全响应头已配置 CSP、X-Frame-Options、X-Content-Type-Options、Referrer-Policy、Permissions-Policy；HTTPS 下发送 HSTS。
- [x] 生产错误信息不直接回显堆栈、SQL 错误或路径。
- [x] 安全事件审计不记录密码、验证码、Token 明文。
- [x] `app/config/config.php` 与 `install.lock` 已加入 `.gitignore`。

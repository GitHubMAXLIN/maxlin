# 文章与分类发布模块升级说明

如果你是全新安装，直接访问 `/install/install.php` 安装即可，`database/schema.sql` 已包含文章模块表结构。

如果你已经安装过第一阶段后台，请按下面步骤升级：

1. 备份数据库与网站文件。
2. 保留原来的 `app/config/config.php` 和 `install.lock`。
3. 覆盖新版程序文件。
4. 在数据库中执行：

```sql
source database/blog_module.sql;
```

也可以用 phpMyAdmin / 宝塔数据库导入 `database/blog_module.sql`。

## 百度地图 AK

文章发布页已经预留百度地图小地图选点。需要在 `app/config/config.php` 中加入：

```php
'app' => [
    // ...原配置
    'baidu_map_ak' => '你的百度地图浏览器端AK',
],
```

不配置 AK 时，页面仍可手动填写经纬度和地址。

## 图片上传目录

默认上传目录为：

```text
storage/uploads
```

程序通过 `media.php?id=数字ID` 受控输出图片，不允许用户直接传路径读取文件。`storage/.htaccess` 已禁止 Apache 直接访问该目录。Nginx 环境请参考 `deploy/nginx-security.conf`，禁止直接访问 storage/uploads 并禁止任何上传目录执行 PHP。

## wangEditor

当前包内提供本地编辑器兼容层：

```text
assets/vendor/wangeditor/wangeditor.local.js
```

它用于离线部署和基础富文本编辑。若需要替换为官方 wangEditor，请把官方文件放到本地 vendor 目录并保持后端清洗逻辑不变。不要在生产后台直接加载不可信第三方 CDN 脚本。

## 百度地图 AK 设置升级

本版新增 `site_settings` 表，用于在后台“系统设置”中保存百度地图浏览器端 AK。

旧项目升级时执行：

```sql
SOURCE database/baidu_map_settings.sql;
```

也可以直接在 phpMyAdmin 中选择当前数据库后，打开 `database/baidu_map_settings.sql` 并执行。

发布 / 编辑文章时，定位地址、经度、纬度改为只读展示；真实值由百度地图默认定位、点击地图或拖动标记点后写入隐藏字段，后端仍会做坐标范围与地址长度校验。

## 控制台访问统计升级

本版新增 `site_visit_logs` 表，用于记录前台访问统计。系统会在前台访问或后台控制台加载时尝试自动创建该表；如果数据库账号没有建表权限，请手动执行：

```sql
source database/dashboard_stats_upgrade.sql;
```

统计字段只保存 `ip_hash`、`visitor_hash`、`user_agent_hash` 等哈希值，不保存明文 IP。控制台只展示去重后的 IP、访客数、浏览量，以及新增点赞、踩、留言数量，不展示访问详情。

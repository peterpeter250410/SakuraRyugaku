# WordPressBase 宝塔面板部署指南

## 环境要求

- PHP >= 7.4（推荐 8.0+）
- MySQL >= 5.7 或 MariaDB >= 10.3
- Nginx 或 Apache
- 宝塔面板

## 部署步骤

### 1. 宝塔面板创建站点

1. 登录宝塔面板
2. 网站 > 添加站点
3. 填写域名，选择 PHP 版本（7.4+），创建 MySQL 数据库
4. 记录数据库名、用户名、密码

### 2. 克隆仓库

```bash
cd /www/wwwroot/yourdomain.com
# 如果目录非空，先清空默认文件
rm -rf ./*
git clone https://github.com/你的用户名/WordPressBase.git .
```

### 3. 运行部署脚本

```bash
# 下载最新版 WordPress 核心
bash scripts/deploy.sh

# 或指定版本（推荐用于 PHP 7.4 的版本）
bash scripts/deploy.sh 6.5.5
```

### 4. 配置 wp-config.php

```bash
cp config/wp-config-sample.php wp-config.php
```

编辑 wp-config.php，填入：
- 数据库名、用户名、密码（宝塔创建站点时获得）
- 认证密钥：访问 https://api.wordpress.org/secret-key/1.1/salt/ 获取并替换
- 确认表前缀不是默认的 `wp_`

### 5. 配置 .htaccess

```bash
cp config/.htaccess-sample .htaccess
```

> 注意：如果使用 Nginx，.htaccess 不生效。需要在宝塔面板的 Nginx 配置中添加对应规则。

### 6. Nginx 用户安全配置（替代 .htaccess）

如果使用 Nginx，在宝塔面板 > 网站 > 站点设置 > 配置文件中添加：

```nginx
# 禁止访问敏感文件
location ~ ^/(wp-config\.php|xmlrpc\.php|readme\.html|license\.txt) {
    deny all;
}

# 禁止 uploads 目录执行 PHP
location ~* /wp-content/uploads/.*\.php$ {
    deny all;
}

# 禁止访问隐藏文件
location ~ /\. {
    deny all;
}

# 禁止目录浏览
autoindex off;
```

### 7. 完成安装

1. 浏览器访问你的域名
2. 按照 WordPress 安装向导完成设置
3. 不要使用 `admin` 作为管理员用户名

### 8. 安装后检查

```bash
# 运行安全检查
bash scripts/security-check.sh

# 删除不需要的文件
rm -f readme.html license.txt wp-config-sample.php
```

### 9. 配置 SSL

1. 宝塔面板 > 网站 > SSL
2. 选择 Let's Encrypt，申请免费证书
3. 开启强制 HTTPS

### 10. 设置定时任务（宝塔计划任务）

| 任务 | 执行周期 | 命令 |
|------|----------|------|
| 数据库备份 | 每天凌晨 2:00 | `bash /www/wwwroot/yourdomain.com/scripts/backup.sh` |
| 安全检查 | 每周一 3:00 | `bash /www/wwwroot/yourdomain.com/scripts/security-check.sh` |

## 更新 WordPress 核心

```bash
cd /www/wwwroot/yourdomain.com
# 指定新版本号
bash scripts/deploy.sh 6.6.0
```

## 更新自定义代码

```bash
cd /www/wwwroot/yourdomain.com
git pull origin main
```

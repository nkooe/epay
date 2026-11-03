# 易支付管理系统

搭配易管理 App 使用。当前源码版本 1.2.30，数据库版本 2064。

## 全新安装

1. 使用 PHP 8.1 或更新版本、MySQL 或 MariaDB，启用 pdo_mysql、curl、openssl、mbstring、bcmath。
2. 将支付系统源码解压到网站根目录，按服务器类型配置 nginx.txt、IIS.txt 或 .htaccess 中的规则。
3. 创建空数据库，访问域名后自动进入安装页面。填写数据库连接资料，安装器一次建立支付系统、后台任务和 App 接口的全部数据表。
4. 安装成功后登录后台，立即修改管理员密码和支付密码，再配置支付通道。

本版只提供全新安装。数据库结构仅维护 install/install.sql，不附带旧版升级入口。已有数据的数据库会拒绝安装。
config.php 和 install/install.lock 由安装器生成，不包含在源码包中。

## 后台进程

进程管理要求 Linux、systemd 250+、cgroup v2，以及包含 pcntl、posix、proc_open 的 PHP CLI。网站与命令行 PHP 均须满足扩展要求。

在站点根目录以 root 安装进程管理组件：

```sh
php bin/install-task-worker.php --install --user=网站运行用户 --php=/PHP命令行完整路径
```

随后在后台的进程管理页面启动服务，并按需启用开机自启。支付监控按已启用的通道自动接管；投诉管理添加支付宝或微信通道后，默认每天北京时间 03:00 同步。

投诉凭据只读取关联通道。微信证书通过易管理上传，统一存储在网站目录外的私有证书目录。缺少证书时明确报错。

## 易管理 App

App 源码位于 native-admin/android，使用 JDK 17、Android SDK 35 和仓库附带的 Gradle Wrapper。配置本机 Android SDK 路径后执行：

```sh
cd native-admin/android
./gradlew :app:testDebugUnitTest :app:assembleDebug
```

Windows 使用 gradlew.bat。正式构建需要在本机配置签名，证书指纹必须与 release-integrity.properties 一致；签名私钥和本机配置不进入源码包。
收款通知使用用户主动开启的常驻事件连接，停止连接后不再进行备用后台轮询。

## 源码打包

```sh
python native-admin/package.py --sources --output native-admin/dist/clean
```

分别生成支付系统源码包、纯 App 源码包及外部校验文件。App 包不包含服务端、APK 或签名；系统包仅包含运行文件和唯一安装 SQL。源码包排除 Markdown、测试、开发记录、缓存、旧补丁、生产配置与私钥。
公共支付系统源码使用 --public 参数生成，仅保留 plugins/.htaccess。
第三方依赖保留运行文件及必要版权、许可证声明。

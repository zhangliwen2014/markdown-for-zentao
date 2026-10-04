<?php
/**
 * Post-install hook for mdeditor plugin.
 *
 * 插件文件解包即完成；但 20.6 运行在 Apache mod_php 常驻 worker 下，
 * helper::import 静态缓存会保留旧 hook（钉钉插件先例），安装/卸载后必须：
 *   rm -rf /apps/zentao/tmp/cache/*
 *   docker exec zentao20.6 /opt/zbox/bin/apachectl restart
 * 此步骤 hook 内无法可靠代做（web 进程不能重启自身），README 明示由用户执行。
 */

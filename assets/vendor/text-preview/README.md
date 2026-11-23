# 文本预览本地依赖

运行时只读取本站文件，不连接CDN。

| 包 | 固定版本 | 文件 | SHA-256 |
|---|---|---|---|
| markdown-it | 15.0.3 | markdown-it-15.0.3.min.js | 391f2aa638e75e6c7859d0a5c5650ef0956722a45998f17e05b83bc655778e1e |
| markdown-it | 15.0.3 | markdown-it-LICENSE | 792c48c5a849a15fdf9e37e8bcf9e6d1dd13b32b46c642a748a0a46a9919d473 |
| @highlightjs/cdn-assets | 11.12.0 | highlight-11.12.0.min.js | 8ab71eb09c51f501e5e25157d9cff100e46cc29bcbfc744d0b746d451fca7f53 |
| @highlightjs/cdn-assets | 11.12.0 | highlight-LICENSE | 6c081431591d9df696c82dc598fe1423765b8a299b200ed00b281afd0f64c490 |

来源为官方npm注册表：https://registry.npmjs.org/markdown-it/15.0.3 ，https://registry.npmjs.org/@highlightjs/cdn-assets/11.12.0 。下载时验证注册表给出的SHA-512 integrity后只提取所列文件。原文件未修改，许可证在相邻LICENSE文件。

markdown-it使用html:false、linkify:false、禁用图片；链接再以允许的协议过滤。高亮只对已知语言运行，大文件保持纯文本；CSP禁止外部连接、图片、内联脚本及对象。

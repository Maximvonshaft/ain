# 订单地名匹配导出工具

该小工具位于 `apps/order-matcher` 目录，支持将订单表中的城市/省份与词表进行相似度匹配，输出命中结果、低置信度复核池以及异常数据。

## 快速开始

1. 复制配置文件：
   ```bash
   cp apps/order-matcher/config.example.json apps/order-matcher/config.json
   ```
2. 根据实际情况修改 `config.json` 中的输入文件路径、列字母、阈值等参数。
3. 运行匹配：
   ```bash
   php apps/order-matcher/match.php --config=apps/order-matcher/config.json
   ```

若不指定 `--config`，脚本会默认查找同目录下的 `config.json`，找不到时自动回退到 `config.example.json`。

## 输出文件

| 文件 | 说明 |
| --- | --- |
| `命中订单.csv` | 高/中置信度命中结果。包含订单原始城市、省份、匹配对象、得分、来源、置信度标签、运行时间与配置ID。 |
| `低置信度待人工复核.csv` | 低置信度命中与多候选冲突条目，附 TOP3 候选得分，方便人工复核。 |
| `异常数据.csv` | 缺失地址或未命中的订单及原因。 |
| `中置信抽检样本.csv` | 按配置比例从中置信结果中抽样，便于人工质检（当存在中置信命中时生成）。 |
| `run_log.json` | 运行摘要、阈值、统计指标、告警信息、列有效值占比等，便于审计追踪。 |

所有输出文件会写入配置文件中 `output_dir` 指定的目录，默认会自动创建。

## 配置要点

- **列映射**：支持 Excel 字母（如 `BK`）指向目标列，读取时自动转换成索引。
- **阈值与加权**：通过 `thresholds` 与 `weights` 控制匹配松紧度；权重会自动归一化。
- **候选削减**：脚本按首字母前缀建立桶，限制最大候选数（`max_candidates`），保证性能。
- **归一化**：支持重音去除、噪音词清洗、别名折叠以及保留自定义字符集。
- **抽检比例**：`sampling_ratio` 控制中置信抽样，0 表示关闭。

更多匹配策略细节请参阅同目录下的 [MATCH_ENGINE.md](MATCH_ENGINE.md)。

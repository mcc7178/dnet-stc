<?php
namespace App\Module\Sale\Data;

/**
 * 新新二手机字典配置
 */
class XinxinDictData
{
    /**
     * 平台
     */
    const PLAT = 21;

    /**
     * 新新商品级别
     * @var array
     */
    const PRD_LEVEL = [
        11 => [
            'label' => 'A1',
            'alias' => '99新',
            'rmk' => '屏靓、壳靓：无明显使用痕迹，充新。',
        ],
        12 => [
            'label' => 'A2',
            'alias' => '95新',
            'rmk' => '屏靓、壳靓：屏幕或壳允许少量细微划痕、细微小磕碰，有使用痕迹。（例：安卓机屏幕支架发黄，耳机孔尾插听筒有使用痕迹，包括灰尘）。',
        ],
        13 => [
            'label' => 'A3',
            'alias' => '9新',
            'rmk' => '①苹果：屏小花或大花（允许2处磕印）、壳靓或小花：屏幕允许多处轻微划痕，外壳允许少量轻微磕碰、划痕、掉漆 、凹痕，轻微亮点，轻微老化（允许：摄像头轻微水印，爱思沙漏显示换电池、前摄像头或后摄像头）；②安卓：屏靓或小花（允许2处磕印）、壳小花或大花：屏幕允许少量轻微划痕 ，外壳允许多处轻微磕碰、划痕与掉漆或三处以内明显磕碰、轻微凹痕、轻微亮点、轻微红屏、轻微老化（允许：摄像头轻微水印）。',
        ],
        21 => [
            'label' => 'B1',
            'alias' => '8新',
            'rmk' => '①苹果：屏大花、壳大花：屏幕少量明显划痕（N＜5），外壳明显磕碰，明显划痕，明显氧化掉漆，轻微凹痕，液晶明显背光、老化、红屏（允许：摄像头明显水印，爱思沙漏显示换电池、前摄像头或后摄像头）；②安卓：屏大花、壳大花：屏幕明显划痕（贴膜不可见），外壳明显磕碰，明显划痕，明显氧化掉漆，轻微凹痕，液晶明显背光、老化、红屏（允许：摄像头明显水印）。',
        ],
        22 => [
            'label' => 'B2',
            'alias' => '7新',
            'rmk' => '屏大花、壳大花：屏幕多处明显划痕（少量硬伤N≤3），外壳多处明显划痕，明显磕碰，明显氧化掉漆，轻微凹痕，液晶明显老化，明显背光 （允许：摄像头明显水印，苹果在爱思沙漏显示换电池、前摄像头或后摄像头）。',
        ],
        31 => [
            'label' => 'C1',
            'alias' => '',
            'rmk' => '主板功能完好，除轻微/明显碎角(N≤2），后壳、屏幕轻度脱胶，轻微压伤（坏点）、苹果后压盖板，整体机况B1级别以上。',
        ],
        32 => [
            'label' => 'C2',
            'alias' => '',
            'rmk' => '主板功能完好，屏幕多处明显硬伤(3≤N）、屏幕四角或周边部分破裂不影响使用(2＜N≤4）、液晶严重老化、大背光亮块、明显压伤、支架缺损、机身轻微弯曲、摄像头镜面裂痕，后壳破裂或四角部分缺角(N≤4）、外壳严重磕碰、明显凹陷、严重氧化掉漆、等级成色细节参考质检描述和图片。',
        ],
    ];

    /**
     * 采购需求-选项大类
     * @var array
     */
    const PCS_OPTION = [
        17000 => '内存',
        14000 => '版本',
        16000 => '颜色',
        15000 => '网络制式'
    ];

    /**
     * 采购需求-成色选项
     * @var array
     */
    const PCS_LEVEL = [
        11 => 'A1(99新)',
        12 => 'A2(95新)',
        13 => 'A3(9成新)',
        21 => 'B1(8成新)',
        22 => 'B2(7成新)',
        31 => 'C1',
        32 => 'C2',
    ];

    /**
     * 采购需求-状态
     * @var array
     */
    const PCS_STAT = [
        1 => '处理中',
        2 => '已过期',
        3 => '已取消'
    ];

    /**
     * 采购需求-取消原因
     * @var array
     */
    const PCS_CREASON = [
        1 => '价格偏高',
        2 => '客户不想要了',
        3 => '客户随便填写',
        4 => '客户拒加微信',
        5 => '客户号码为空号',
        6 => '联系不到客户',
        7 => '平台上有',
        8 => '客户已经购买',
        9 => '其他',
    ];

    /**
     * 采购需求-流水类型
     * @var array
     */
    const PCS_WATER = [
        1 => '挂起',
        2 => '提交采购单',
        3 => '取消采购单',
        4 => '转给客服',
        5 => '采购完成',
    ];

    /**
     * 采购需求-机型版本映射
     * @var array
     */
    const PCS_MDOFSALE = [
        14201 => '国行',
        14107 => '其他版本'
    ];

    /**
     * 采购需求-网络制式映射
     * @var array
     */
    const PCS_MDNET = [
        15101 => '全网通',
        15601 => '其他'
    ];

    /**
     * 采购需求-网络制式特殊处理的机型
     * @var array
     */
    const PCS_SPECIALMID = [
        10007, //iPhone 6
        10008, //iPhone 6 Plus
    ];

    /**
     * 品牌名称和图标
     */
    const ICONS = [
        99 => ['lable' => '混合场', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/other.jpg'],
        10000 => ['lable' => '苹果', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/iphone.png'],
        20000 => ['lable' => 'OPPO', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/oppo.png'],
        30000 => ['lable' => '三星', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/snmsung.png'],
        40000 => ['lable' => '华为', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/huawei.png'],
        50000 => ['lable' => '小米', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/mi.png'],
        60000 => ['lable' => '魅族', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/meizu.png'],
        80000 => ['lable' => 'VIVO', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/vivo.png'],
        210000 => ['lable' => '荣耀', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/honor.png'],
        240000 => ['lable' => '美图', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/meitu.png'],
        400000 => ['lable' => '苹果平板', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/iphone.png'],
        2000080000 => ['lable' => 'OPPO&VIVO', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/oppo_vivo.png'],
        40000210000 => ['lable' => '华为&荣耀', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/huawei_honor.png'],
        3000060000 => ['lable' => '魅族&三星', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/meizu_sumsung.png'],
        4000050000 => ['lable' => '华为&小米', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/huawei_mi.png'],
        60000240000 => ['lable' => '魅族&美图', 'icon1' => 'https://img.sosotec.com/xinxin/mcp/round/brand/1/meizu_meitu.png'],
    ];

    /**
     * 竞拍状态
     */
    const BID_STAT = [
        11 => '待公开',
        12 => '待开场',
        13 => '竞拍中',
        21 => '中标',
        22 => '流标',
        31 => '取消',
        32 => '退货',
    ];

    /**
     * 团购规则
     */
    const GROUPBUY_RULES = [
        'express' => ['48小时发货', '30天质保', '顺丰包邮'],
        'group' => [
            '活动时间内达到指定成团数量则拼团成功，将在24小时内发货，未达到成团数量则拼团失败，款项将在拼团结束后24小时内原路退回',
            '在“我的-订单”，可以查看拼团订单，发货后可查看机器的照片及质检报告。',
            '如有疑问请联系在线客服。',
        ]
    ];

    /**
     * 竞拍订单取消时间
     */
    const BID_CANCEL_TIME = 10800;

    /**
     * 一口价订单取消时间
     */
    const SHOP_CANCEL_TIME = [
        'cart' => 300,
        'order' => 900,
        'offpay' => 86400,
    ];

    /**
     * 团购订单超时时间
     */
    const GROUP_ORDER_CANCEL_TIME = [
        'order' => 600,
        'offpay' => 86400,
    ];

    //新新保证金金额
    const DEPOSIT = 100;
}
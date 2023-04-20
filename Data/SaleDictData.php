<?php
namespace App\Module\Sale\Data;

/**
 * 销售系统字典配置
 */
class SaleDictData
{
    /**
     * 竞拍场次类型
     */
    const BID_ROUND_TID = [
        0 => '日常',
        1 => '活动',
        2 => '特价',
    ];

    /**
     * 竞拍模式
     */
    const BID_MODE = [
        1 => '明拍',
        2 => '暗拍',
    ];

    /**
     * 场次状态
     */
    const BID_ROUND_STAT = [
        11 => '待公开',
        12 => '待开场',
        13 => '竞拍中',
        14 => '已结束',
    ];

    /**
     * 广告投放位置
     */
    const CMS_ADVERT_DISTPOS = [
        1000 => '首页-轮播图',
        2000 => '个人中心-轮播图',
    ];

    /**
     * 广告投放渠道
     */
    const CMS_ADVERT_DISTCHN = [
        1 => 'IOS',
        2 => 'Android',
        3 => '微信',
        4 => '支付宝',
    ];

    /**
     * 竞拍商品状态
     */
    const BID_SALES_STAT = [
        11 => '待公开',
        12 => '待开场',
        13 => '竞拍中',
        21 => '中标',
        22 => '流标',
        31 => '取消',
        32 => '退货',
    ];

    /**
     * 一口价商品状态
     */
    const SHOP_SALES_STAT = [
        11 => '编辑中',
        31 => '销售中',
        32 => '冻结中',
        33 => '已销售',
    ];

    /**
     * stc_storage 商品仓库状态
     */
    const PRD_STORAGE_STAT = [
        11 => '在库',
        12 => '预出库',
        13 => '出库中',
        14 => '上架中',
        15 => '报价中',
        21 => '外借中',
        22 => '出库',
        23 => '已售',
        30 => '待竞拍',
        32 => '竞拍中标',
        33 => '竞拍流标',
        34 => '超时取消',
        35 => '后台取消',
    ];

    /**
     * 商品状态
     */
    const PRD_PRODUCT_STAT = [
        1 => '在库',
        2 => '出库',
        3 => '盘亏',
    ];

    /**
     * 订单状态
     */
    const ODR_ORDER_STAT = [
        0 => '待提交',
        10 => '待确认',
        11 => '待支付',
        12 => '待审核',
        13 => '已支付',
        21 => '待发货',
        22 => '已发货',
        23 => '交易完成',
    ];

    /**
     * 商品来源平台
     */
    const SOURCE_PLAT = [
        11 => '微回收',
        13 => '阿曼里',
        16 => '闲鱼回收',
        17 => '小槌子帮卖',
        18 => '供应商',
        19 => '闲鱼拍卖',
        21 => '新新二手机',
        22 => '小槌子',
        23 => '线下门店',
        24 => '电商采购',
        161 => '闲鱼寄卖',
        162 => '闲鱼优品',
    ];

    /**
     * 品牌ID的组合按升序排列组合拼接 例如：2000080000
     */
    const ROUND_GROUPKEY = [
        ['id' => 99, 'text' => '混合场', 'icon' => 'https://mis.sosotec.com/images/mlogo/other.png'],
        ['id' => 10000, 'text' => '苹果', 'icon' => 'https://mis.sosotec.com/images/mlogo/iphone.png'],
        ['id' => 20000, 'text' => 'OPPO', 'icon' => 'https://mis.sosotec.com/images/mlogo/oppo.png'],
        ['id' => 30000, 'text' => '三星', 'icon' => 'https://mis.sosotec.com/images/mlogo/snmsung.png'],
        ['id' => 40000, 'text' => '华为', 'icon' => 'https://mis.sosotec.com/images/mlogo/huawei.png'],
        ['id' => 50000, 'text' => '小米', 'icon' => 'https://mis.sosotec.com/images/mlogo/mi.png'],
        ['id' => 60000, 'text' => '魅族', 'icon' => 'https://mis.sosotec.com/images/mlogo/meizu.png'],
        ['id' => 80000, 'text' => 'VIVO', 'icon' => 'https://mis.sosotec.com/images/mlogo/vivo.png'],
        ['id' => 210000, 'text' => '荣耀', 'icon' => 'https://mis.sosotec.com/images/mlogo/honor.png'],
        ['id' => 240000, 'text' => '美图', 'icon' => 'https://mis.sosotec.com/images/mlogo/meitu.png'],
        ['id' => 400000, 'text' => '苹果平板', 'icon' => 'https://mis.sosotec.com/images/mlogo/iphone.png'],
        ['id' => 2000080000, 'text' => 'OPPO&VIVO', 'icon' => 'https://mis.sosotec.com/images/mlogo/oppo_vivo.png'],
        ['id' => 40000210000, 'text' => '华为&荣耀', 'icon' => 'https://mis.sosotec.com/images/mlogo/huawei_honor.png'],
        ['id' => 3000060000, 'text' => '魅族&三星', 'icon' => 'https://mis.sosotec.com/images/mlogo/meizu_sumsung.png'],
        ['id' => 4000050000, 'text' => '华为&小米', 'icon' => 'https://mis.sosotec.com/images/mlogo/huawei_mi.png'],
        ['id' => 60000240000, 'text' => '魅族&美图', 'icon' => 'https://mis.sosotec.com/images/mlogo/meizu_meitu.png'],
    ];

    /**
     * 商品库存状态
     */
    const PRD_STCSTAT = [
        11 => '待上架',
        14 => '上架中',
        15 => '报价中',
        23 => '已售',
        31 => '竞拍中',
        32 => '竞拍中标',
        33 => '竞拍流标',
        34 => '超时取消',
    ];

    /**
     * 闲鱼帮卖平台
     */
    const XYC_BM_PLAT = 161;

    /**
     * 订单类型
     */
    const ORDER_TYPE = [
        2111 => '新新竞拍订单',
        2211 => '小槌子竞拍订单',
        11 => '竞拍订单',
        12 => '一口价订单',
        21 => '线下订单',
        22 => '外发订单',
        31 => '内购订单',
        32 => '第三方订单',
        33 => '自建订单',
        34 => 'B2C淘宝',
        35 => 'B2C微信',
        36 => '闲鱼优品',
    ];

    /**
     * 订单状态
     */
    const ORDER_OSTAT = [
        0 => '待提交',
        10 => '待成交',
        11 => '待支付',
        12 => '待审核',
        13 => '已支付',
        21 => '待发货',
        22 => '已发货',
        23 => '交易完成',
        51 => '取消交易',
        52 => '扣除押金',
        53 => '购买失败',
    ];

    /**
     * 出库单接收仓库
     */
    const STC_SOURCE = [
        101 => '仓储部',
        103 => '小槌子部',
        104 => '良品部',
    ];

    /**
     * 商品售出平台
     */
    const SOLD_PLAT = [
        19 => '闲鱼拍卖',
        21 => '新新二手机',
        22 => '小槌子',
        23 => '线下门店',
        24 => 'B2C电商',
    ];

    /**
     * 商品销售方式
     */
    const SOLD_METHOD = [
        11 => '竞拍',
        12 => '秒杀',
        13 => '一口价',
        14 => '流拍兜底',
        21 => '门店报价',
        22 => '外单报价',
        23 => '内购',
        31 => '拼多多',
        32 => '闲鱼',
    ];

    /**
     * 闲鱼优品店铺映射订单来源
     */
    const XYE_SHOP_MAPPING_ODR_SRC = [
        '5fae541da3867312be2d1396' => 36004, //大满仓优品
        '5fae541da3867312be2d1398' => 36006, //真靓机优品
        '5fae541da3867312be2d1399' => 36007, //靓选优品
        '5fae541da3867312be2d139c' => 36009, //拍靓机
        '5fae541da3867312be2d139e' => 36001, //云仓优品二手数码
        '5fae541da3867312be2d139f' => 36002, //收收优品
        '5fae541da3867312be2d13a0' => 36003, //废铁战士Pro
        '5fae541da3867312be2d1397' => 36005, //诺金优品
        '5fae541da3867312be2d139b' => 36008, //功达优品
        '5fbf67bca3867310b741e421' => 36010, //宝为优品乐购
        '5fbf67bca3867310b741e422' => 36011, //先我优品
        '5fc74f55a38673d3796c963e' => 36012, //闲淘二手优品
        '5fc74f55a38673d3796c963f' => 36013, //淘闲优品
        '5fc74f55a38673d3796c9640' => 36014, //白小白二手优品
        '5fae541da3867312be2d139a' => 36015, //优嘉优品
    ];

    /**
     * 淘宝发货状态
     */
    const ODR_TAOBAO_OSTAT = [
        20 => '待配货',
        21 => '待发货',
        22 => '待签收',
        23 => '交易完成',
        51 => '已关闭',
    ];

    /**
     * 淘宝订单状态
     */
    const TAOBAO_STATUS = [
        11 => '等待买家付款',
        12 => '等待卖家发货',
        13 => '卖家部分发货',
        14 => '等待买家确认收货',
        21 => '交易成功',
        31 => '主动关闭交易',
        32 => '自动关闭交易',
        33 => '自动关闭交易'
    ];

    /**
     * 淘宝内部退款状态
     */
    const XYE_SALE_GOODS_REFSTAT = [
        11 => '等待卖家同意',
        12 => '等待买家退货',
        13 => '等待卖家确认收货',
        14 => '卖家拒绝退款',
        15 => '退款关闭',
        16 => '退款成功',
    ];

    /**
     * 订单商品的配货状态
     */
    const ORDER_DSTAT = [
        1 => '待配货',
        2 => '已配货',
        3 => '待发货',
        4 => '已发货',
        5 => '已取消',
    ];

    /**
     *淘宝订单流水类型
     */
    const  TAOBAO_WATER = [
        1 => '生成订单',
        2 => '完成配货',
        3 => '确认发货',
        4 => '取消发货',
        5 => '取消配货',
        6 => '不发货 ',
        7 => '打单发货',
        8 => '发货撤回',
        9 => '交易成功',
        10 => '交易关闭',
    ];
}
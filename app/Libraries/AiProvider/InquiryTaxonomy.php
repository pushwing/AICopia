<?php

namespace App\Libraries\AiProvider;

/**
 * 문의 분류 enum 허용값.
 * 트레이트 상수는 외부에서 직접 접근할 수 없어, 컨트롤러·트레이트가 공유하도록 별도 클래스로 둔다.
 */
final class InquiryTaxonomy
{
    public const CATEGORIES = ['shipping', 'refund', 'product', 'payment', 'etc'];
    public const PRIORITIES = ['high', 'normal', 'low'];
    public const SENTIMENTS = ['positive', 'neutral', 'negative'];
}

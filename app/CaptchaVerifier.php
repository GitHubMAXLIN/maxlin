<?php

declare(strict_types=1);

final class CaptchaVerifier
{
    /**
     * 人机验证预留接口：接入 Turnstile、reCAPTCHA、滑块验证码或自建验证码时，
     * 在这里完成服务端校验。前端展示不能替代这里的后端判断。
     */
    public static function verifyIfRequired(bool $required, array $post): bool
    {
        if (!$required) {
            return true;
        }

        // 第一阶段只保留接口，不阻断正常登录；正式上线前请改为真实验证码校验。
        // 示例：$captchaResponse = (string)($post['captcha_response'] ?? '');
        return true;
    }
}

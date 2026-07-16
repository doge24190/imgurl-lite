<div class="layui-container setting">
    <div class="layui-row layui-col-space20">
        <div class="layui-col-lg7 layui-col-md12">
            <div class="setting-msg">
                图片验证码始终启用。这里只开放安全阈值，签名密钥和来源标识不会显示或写入页面。
            </div>
            <form class="layui-form" action="/set/security" method="post">
                <div class="layui-form-item">
                    <label class="layui-form-label">验证码长度</label>
                    <div class="layui-input-block">
                        <input type="number" name="captcha_length" min="4" max="8" required lay-verify="required|number" class="layui-input" value="<?php echo (int)$captcha_length; ?>">
                        <div class="layui-form-mid layui-word-aux">允许 4–8 个字符，建议保持 5。</div>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">验证码有效期</label>
                    <div class="layui-input-block">
                        <input type="number" name="captcha_ttl" min="60" max="900" required lay-verify="required|number" class="layui-input" value="<?php echo (int)$captcha_ttl; ?>">
                        <div class="layui-form-mid layui-word-aux">单位：秒，允许 60–900。</div>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">失败次数</label>
                    <div class="layui-input-block">
                        <input type="number" name="max_attempts" min="3" max="20" required lay-verify="required|number" class="layui-input" value="<?php echo (int)$max_attempts; ?>">
                        <div class="layui-form-mid layui-word-aux">验证码或密码错误均会累计，允许 3–20 次。</div>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">统计窗口</label>
                    <div class="layui-input-block">
                        <input type="number" name="attempt_window" min="60" max="86400" required lay-verify="required|number" class="layui-input" value="<?php echo (int)$attempt_window; ?>">
                        <div class="layui-form-mid layui-word-aux">单位：秒，默认 900 秒（15 分钟）。</div>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">锁定时长</label>
                    <div class="layui-input-block">
                        <input type="number" name="lockout" min="60" max="86400" required lay-verify="required|number" class="layui-input" value="<?php echo (int)$lockout; ?>">
                        <div class="layui-form-mid layui-word-aux">单位：秒，默认 900 秒（15 分钟）。</div>
                    </div>
                </div>
                <div class="layui-form-item">
                    <div class="layui-input-block">
                        <button class="layui-btn" lay-submit>保存安全设置</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="layui-col-lg5 layui-col-md12">
            <table class="layui-table">
                <thead>
                    <tr><th colspan="2">运行状态</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>安全存储</td>
                        <td><?php echo $storage_writable ? '可写' : '不可写'; ?></td>
                    </tr>
                    <tr>
                        <td>签名密钥</td>
                        <td><?php echo $secret_initialized ? '已初始化' : '未初始化'; ?></td>
                    </tr>
                    <tr>
                        <td>限速记录</td>
                        <td><?php echo (int)$attempt_record_count; ?></td>
                    </tr>
                    <tr>
                        <td>当前锁定</td>
                        <td><?php echo (int)$locked_record_count; ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="layui-elem-quote">
                设置保存到数据库并立即对新的登录请求生效；无需重启 PHP-FPM 或 Nginx。
            </div>
        </div>
    </div>
</div>

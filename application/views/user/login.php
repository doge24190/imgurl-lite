<!-- 内容部分 -->
<div id="container">
    <div class="layui-container">
        <div class="layui-row">
            <div class="layui-col-lg4 layui-col-md-offset4">
                <!-- 登录表单 -->
                <div class="login">
                    <div class="layui-form-item">
                        <input id = "user" type="text" name="title" required  lay-verify="required" placeholder="用户名" autocomplete="off" class="layui-input">
                    </div>
                    <div class="layui-form-item">
                         <input id = "password" type="password" name="password" required lay-verify="required" placeholder="密码" autocomplete="off" class="layui-input">
                    </div>
                    <div class="layui-form-item captcha-row">
                        <input id="captcha" type="text" name="captcha" required lay-verify="required" placeholder="验证码" autocomplete="off" maxlength="5" class="layui-input captcha-input" aria-label="验证码">
                        <button type="button" class="captcha-refresh" onclick="refreshCaptcha()" title="点击更换验证码" aria-label="更换验证码">
                            <img id="captcha-image" src="/user/captcha" alt="登录验证码">
                        </button>
                    </div>
                    <div class="captcha-help">看不清？点击图片更换</div>
                    <div class="layui-form-item">
                        <button id="login-button" type="button" class="layui-btn" lay-submit lay-filter="formDemo" onclick="login()">登录</button>
                    </div>
                </div>
                <!-- 登录表单END -->
            </div>
        </div>
    </div>
</div>
<!-- 内容部分end -->

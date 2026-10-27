<div class="layui-container setting" style="padding-bottom:40px">
    <h1 style="font-size:24px;margin:20px 0">API 令牌</h1>
    <div class="layui-elem-quote">
        为脚本或自动化工具单独创建令牌，按用途选择权限。完整令牌只显示一次，生产环境请通过 HTTPS 使用。修改后台账号或密码后，已有令牌会失效。
        <a href="/page/api" target="_blank" rel="noopener" style="color:#007f73">查看 API 使用说明</a> ·
        <a href="/static/openapi.json" target="_blank" rel="noopener" style="color:#007f73">OpenAPI 文档</a>
    </div>
    <?php if ($message !== ''): ?><p role="status" class="layui-elem-quote"><?php echo h($message); ?></p><?php endif; ?>
    <?php if ($secret !== null): ?>
    <div class="layui-form-item">
        <label for="api-created-token">刚创建的令牌（离开本页后无法再次查看，请妥善保存）</label>
        <input id="api-created-token" class="layui-input" type="text" readonly spellcheck="false" autocomplete="off" value="<?php echo h($secret); ?>" style="margin-top:10px;font-family:monospace">
    </div>
    <?php endif; ?>
    <fieldset class="layui-elem-field" style="padding:20px;margin-top:24px">
        <legend>创建令牌</legend>
        <form class="layui-form" action="/tokens/create" method="post" autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="layui-form-item">
                <label class="layui-form-label" for="api-token-name">名称</label>
                <div class="layui-input-block"><input id="api-token-name" name="name" class="layui-input" maxlength="80" required lay-verify="required" placeholder="例如：部署健康检查、图片查询脚本"></div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label" for="api-token-days">有效天数</label>
                <div class="layui-input-block"><input id="api-token-days" type="number" name="days" min="1" max="365" value="30" class="layui-input" required lay-verify="required|number"><p class="layui-word-aux" style="margin-top:8px">1–365 天，默认 30 天。到期后请创建新令牌。</p></div>
            </div>
            <div class="layui-form-item">
                <span class="layui-form-label">允许操作</span>
                <div class="layui-input-block">
                    <?php foreach ($scopes as $scope => $label): ?>
                    <div style="margin:8px 0"><input type="checkbox" name="scopes[]" value="<?php echo h($scope); ?>" title="<?php echo h($label); ?>" lay-skin="primary" <?php if ($scope === 'health:read') { echo 'checked'; } ?>></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" type="submit" lay-submit>创建令牌</button></div></div>
        </form>
    </fieldset>
    <h2 style="font-size:20px;margin:24px 0 12px">现有令牌</h2>
    <div style="overflow-x:auto">
        <table class="layui-table">
            <thead><tr><th>名称 / 前缀</th><th>权限</th><th>创建时间</th><th>到期时间</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $token): ?>
                <tr>
                    <td><?php echo h($token['name']); ?><br><code><?php echo h($token['prefix']); ?>…</code></td>
                    <td><?php foreach ($token['scopes'] as $scope) { echo h($scopes[$scope] ?? $scope).'<br>'; } ?></td>
                    <td><?php echo h(date('Y-m-d H:i T', $token['created_at'])); ?></td>
                    <td><?php echo h(date('Y-m-d H:i T', $token['expires_at'])); ?></td>
                    <td><?php echo $token['active'] ? '有效' : '已失效'; ?></td>
                    <td><form action="/tokens/revoke" method="post"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo h($token['id']); ?>"><button type="submit" class="layui-btn layui-btn-danger layui-btn-sm">撤销</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tokens): ?><tr><td colspan="6">尚未创建令牌。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

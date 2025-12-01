<?php
/** @var array<string, mixed> $payload */
/** @var array<int, array<string, mixed>> $items */
/** @var string|null $errorMessage */

$estado = strtoupper((string) ($payload['resultado'] ?? ''));
$estadoClase = $estado === 'OK' ? 'text-success' : 'text-danger';
$total = (int) ($payload['total'] ?? 0);
?>
    <section class="container my-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h1 class="h4 mb-3">Comprobante BancoEstado</h1>
                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-warning">
                                <?= h($errorMessage); ?>
                            </div>
                        <?php endif; ?>
                        <ul class="list-unstyled mb-0">
                            <li><strong>Orden:</strong> <?= h((string) ($payload['oc'] ?? '')); ?></li>
                            <li><strong>Estado:</strong> <span class="<?= $estadoClase; ?>"><?= h($estado !== '' ? $estado : 'DESCONOCIDO'); ?></span></li>
                            <li><strong>Total:</strong> <?= h(format_currency($total)); ?></li>
                            <li><strong>Fecha:</strong> <?= h((string) ($payload['fecha'] ?? '')); ?> <?= h((string) ($payload['hora'] ?? '')); ?></li>
                        </ul>
                    </div>
                </div>

                <?php if (!empty($items)): ?>
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white">
                            <h2 class="h5 mb-0">Detalle de servicios</h2>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead>
                                    <tr>
                                        <th>Contrato</th>
                                        <th>Servicio</th>
                                        <th>Periodo</th>
                                        <th class="text-right">Monto</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td><?= h((string) ($item['idcliente'] ?? '')); ?></td>
                                            <td><?= h((string) ($item['servicio'] ?? '')); ?></td>
                                            <td><?= h((string) ($item['mes'] ?? '')); ?>/<?= h((string) ($item['ano'] ?? '')); ?></td>
                                            <td class="text-right"><?= h(format_currency((int) ($item['valor'] ?? $item['monto'] ?? 0))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="text-center">
                    <a href="index.php" class="btn btn-primary">Volver al inicio</a>
                </div>
            </div>
        </div>
    </section>

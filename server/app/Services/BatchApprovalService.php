<?php

namespace App\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Aprobar varias semanas a la vez. La contraseña se verifica una sola vez (quien llama lo hace antes), y cada hoja se aprueba por separado con
 * la misma lógica y las mismas reglas que al aprobarla sola: cada una deja su propia firma, historial y versión. Lo que falla en una hoja
 * no detiene a las demás; el resultado dice cuáles se aprobaron y por qué no las otras.
 */
class BatchApprovalService
{
    /** Tope de hojas por lote. */
    public const MAX = 25;

    /** @return array{sheets: array<int, array{id:int, version:int}>} */
    public function validate(Request $request): array
    {
        return $request->validate([
            'sheets' => 'required|array|min:1|max:'.self::MAX,
            'sheets.*.id' => 'required|integer|distinct',
            'sheets.*.version' => 'required|integer',
        ]);
    }

    /**
     * @param array<int, array{id:int, version:int}> $items
     * @param \Closure(int, int): void $approveOne aprueba una hoja (id, versión) o lanza lo mismo que la ruta de una sola hoja
     * @return array{approved:int, failed:int, results: array<int, array{id:int, ok:bool, message:?string}>}
     */
    public function run(array $items, \Closure $approveOne): array
    {
        $results = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            try {
                $approveOne($id, (int) $item['version']);
                $results[] = ['id' => $id, 'ok' => true, 'message' => null];
            } catch (ValidationException $e) {
                $results[] = ['id' => $id, 'ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
            } catch (HttpResponseException $e) {
                $json = json_decode($e->getResponse()->getContent(), true);
                $results[] = ['id' => $id, 'ok' => false, 'message' => (string) ($json['message'] ?? __('No se pudo aprobar esta hoja.'))];
            } catch (ModelNotFoundException|HttpExceptionInterface $e) {
                $results[] = ['id' => $id, 'ok' => false, 'message' => __('No encontramos esa hoja o no te toca aprobarla.')];
            }
        }
        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        return ['approved' => $ok, 'failed' => count($results) - $ok, 'results' => $results];
    }
}

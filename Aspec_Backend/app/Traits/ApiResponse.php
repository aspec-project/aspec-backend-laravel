namespace App\Traits;

trait ApiResponse
{
    /**
     * success response method.
     *
     * @return \Illuminate\Http\Response
     */
    public function successResponse($data, string $message = null, int $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * error response method.
     *
     * @return \Illuminate\Http\Response
     */
    public function errorResponse(string $message, int $code)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }
}
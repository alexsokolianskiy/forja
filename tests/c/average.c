#include <stdint.h>

int64_t {{symbol}}(int64_t total, int64_t count, int32_t *accel_err)
{
    if (count == 0 || (total == INT64_MIN && count == -1)) {
        *accel_err = 1; /* PHP throws DivisionByZeroError / ArithmeticError */
        return 0;
    }
    return total / count;
}

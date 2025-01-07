<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 定时任务，全部放在队列里面的
 * Uses a binary tree stored in an array to implement a heap.
 *
 * @internal
 */
final class TimerQueue
{
    /** @var array<int, TimerCallback> */
    private array $callbacks = [];

    /** @var array<string, int> */
    private array $pointers = [];

    /**
     * 将回调函数插入到队列
     * Inserts the callback into the queue.
     *
     * Time complexity: O(log(n)).
     */
    public function insert(TimerCallback $callback): void
    {
        \assert(!isset($this->pointers[$callback->id]));
        /** 统计 */
        $node = \count($this->callbacks);
        $this->callbacks[$node] = $callback;
        $this->pointers[$callback->id] = $node;

        $this->heapifyUp($node);
    }

    /**
     * 从队列中移除回调函数
     * Removes the given callback from the queue.
     *
     * Time complexity: O(log(n)).
     */
    public function remove(TimerCallback $callback): void
    {
        $id = $callback->id;

        if (!isset($this->pointers[$id])) {
            return;
        }

        $this->removeAndRebuild($this->pointers[$id]);
    }

    /**
     * 如果回调已过期，则删除并返回堆顶部的回调，否则返回null。
     * Deletes and returns the callback on top of the heap if it has expired, otherwise null is returned.
     *
     * Time complexity: O(log(n)).
     *
     * @param float $now Current event loop time.
     *
     * @return TimerCallback|null Expired callback at the top of the heap or null if the callback has not expired.
     */
    public function extract(float $now): ?TimerCallback
    {
        if (!$this->callbacks) {
            return null;
        }
        /** 如果回调函数还没有到执行时间 ，则返回空 */
        $callback = $this->callbacks[0];
        if ($callback->expiration > $now) {
            return null;
        }

        $this->removeAndRebuild(0);

        return $callback;
    }

    /**
     * 返回顶部回调函数的执行时刻
     * Returns the expiration time value at the top of the heap.
     *
     * Time complexity: O(1).
     *
     * @return float|null Expiration time of the callback at the top of the heap or null if the heap is empty.
     */
    public function peek(): ?float
    {
        return isset($this->callbacks[0]) ? $this->callbacks[0]->expiration : null;
    }

    /**
     * 从给定节点向上重建数据数组。
     * @param int $node Rebuild the data array from the given node upward.
     */
    private function heapifyUp(int $node): void
    {
        $entry = $this->callbacks[$node];
        while ($node !== 0 && $entry->expiration < $this->callbacks[$parent = ($node - 1) >> 1]->expiration) {
            /** 交换数据 */
            $this->swap($node, $parent);
            $node = $parent;
        }
    }

    /**
     * 从给定节点向下重建数据数组。
     * @param int $node Rebuild the data array from the given node downward.
     */
    private function heapifyDown(int $node): void
    {
        $length = \count($this->callbacks);
        while (($child = ($node << 1) + 1) < $length) {
            if ($this->callbacks[$child]->expiration < $this->callbacks[$node]->expiration
                && ($child + 1 >= $length || $this->callbacks[$child]->expiration < $this->callbacks[$child + 1]->expiration)
            ) {
                // Left child is less than parent and right child.
                $swap = $child;
            } elseif ($child + 1 < $length && $this->callbacks[$child + 1]->expiration < $this->callbacks[$node]->expiration) {
                // Right child is less than parent and left child.
                $swap = $child + 1;
            } else { // Left and right child are greater than parent.
                break;
            }

            $this->swap($node, $swap);
            $node = $swap;
        }
    }

    /**
     * 交换回调函数位置
     * @param int $left
     * @param int $right
     * @return void
     */
    private function swap(int $left, int $right): void
    {
        $temp = $this->callbacks[$left];

        $this->callbacks[$left] = $this->callbacks[$right];
        $this->pointers[$this->callbacks[$right]->id] = $left;

        $this->callbacks[$right] = $temp;
        $this->pointers[$temp->id] = $right;
    }

    /**
     * 移除指定节点的回调函数
     * @param int $node Remove the given node and then rebuild the data array.
     */
    private function removeAndRebuild(int $node): void
    {
        $length = \count($this->callbacks) - 1;
        $id = $this->callbacks[$node]->id;
        $left = $this->callbacks[$node] = $this->callbacks[$length];
        $this->pointers[$left->id] = $node;
        unset($this->callbacks[$length], $this->pointers[$id]);

        if ($node < $length) { // don't need to do anything if we removed the last element
            $parent = ($node - 1) >> 1;
            if ($parent >= 0 && $this->callbacks[$node]->expiration < $this->callbacks[$parent]->expiration) {
                $this->heapifyUp($node);
            } else {
                $this->heapifyDown($node);
            }
        }
    }
}
